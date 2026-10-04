<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Enums\GoogleAds\LeadAttributionTouchRole;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsDailyMetric;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsMutation;
use App\Models\GoogleAdsSearchTerm;
use App\Models\GoogleAdsSyncRun;
use App\Models\LeadAttributionTouch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §4 / §10 — the normalised schema and the
 * append-only attribution table: money is BIGINT micros, absence is NULL not
 * 0, natural keys make upserts idempotent, composite foreign keys make a
 * cross-Business reference impossible, and a touch can never be rewritten.
 */
class GoogleAdsSchemaTest extends TestCase
{
    use CreatesGoogleAdsFixtures;
    use RefreshDatabase;

    private const TABLES = [
        'google_ads_accounts', 'google_ads_campaigns', 'google_ads_ad_groups', 'google_ads_keywords',
        'google_ads_search_terms', 'google_ads_daily_metrics', 'google_ads_sync_runs', 'google_ads_mutations',
        'lead_attribution_touches',
    ];

    private Business $business;

    private GoogleAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->adsTenant();
        $this->account = $this->accountFor($this->business);
    }

    private function accountFor(Business $business, string $customerId = '1234567890'): GoogleAdsAccount
    {
        $connection = $this->activeAdsConnection($business);

        return GoogleAdsAccount::create([
            'business_id' => $business->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => $customerId,
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'selected_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function scope(GoogleAdsAccount $account): array
    {
        return ['business_id' => $account->business_id, 'google_ads_account_id' => $account->id];
    }

    private function campaign(?GoogleAdsAccount $account = null, string $externalId = '1000000001'): GoogleAdsCampaign
    {
        return GoogleAdsCampaign::create($this->scope($account ?? $this->account) + [
            'external_campaign_id' => $externalId, 'name' => 'Photo Booth Rental', 'status' => 'ENABLED',
        ]);
    }

    private function adGroup(GoogleAdsCampaign $campaign, string $externalId = '3000000001'): GoogleAdsAdGroup
    {
        return GoogleAdsAdGroup::create([
            'business_id' => $campaign->business_id, 'google_ads_account_id' => $campaign->google_ads_account_id,
            'google_ads_campaign_id' => $campaign->id, 'external_ad_group_id' => $externalId, 'name' => 'General', 'status' => 'ENABLED',
        ]);
    }

    // ---------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------

    public function test_every_table_exists_and_every_index_and_constraint_name_fits_mysql(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $database = DB::getDatabaseName();
        $names = collect(DB::select(
            'select distinct index_name as name from information_schema.statistics where table_schema = ? and table_name in (' . implode(',', array_fill(0, count(self::TABLES), '?')) . ')',
            array_merge([$database], self::TABLES),
        ))->pluck('name')->merge(collect(DB::select(
            'select constraint_name as name from information_schema.table_constraints where table_schema = ? and table_name in (' . implode(',', array_fill(0, count(self::TABLES), '?')) . ')',
            array_merge([$database], self::TABLES),
        ))->pluck('name'))->unique();

        $this->assertGreaterThan(40, $names->count());

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }

        // The hand-named ones follow the short `gads_` / `lat_` convention.
        $custom = $names->reject(fn ($n) => $n === 'PRIMARY' || str_ends_with($n, '_uid_unique'));
        foreach ($custom as $name) {
            $this->assertMatchesRegularExpression('/\A(gads|lat)_/', $name, $name);
        }
    }

    public function test_money_is_bigint_micros_and_conversions_are_nullable_decimal_20_6(): void
    {
        $column = fn (string $table, string $name) => DB::selectOne(
            'select data_type as data_type, column_type as column_type, is_nullable as is_nullable from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $name],
        );

        foreach ([
            ['google_ads_campaigns', 'budget_amount_micros'],
            ['google_ads_search_terms', 'cost_micros'],
            ['google_ads_daily_metrics', 'cost_micros'],
            ['google_ads_accounts', 'monthly_budget_target_micros'],
            ['google_ads_accounts', 'target_cpl_micros'],
        ] as [$table, $name]) {
            $this->assertSame('bigint', strtolower($column($table, $name)->data_type), "$table.$name");
        }

        foreach (['google_ads_search_terms', 'google_ads_daily_metrics'] as $table) {
            foreach (['conversions', 'conversions_value'] as $name) {
                $c = $column($table, $name);
                $this->assertSame('decimal(20,6)', strtolower($c->column_type), "$table.$name");
                $this->assertSame('YES', $c->is_nullable, "$table.$name: absence is NULL, not 0");
            }

            $this->assertSame('NO', $column($table, 'cost_micros')->is_nullable);
        }

        // Targets are "no target" until set.
        $this->assertSame('YES', $column('google_ads_accounts', 'monthly_budget_target_micros')->is_nullable);
        $this->assertSame('NO', $column('google_ads_accounts', 'currency_code')->is_nullable);
        $this->assertSame('NO', $column('google_ads_accounts', 'time_zone')->is_nullable);
    }

    public function test_the_mutation_detail_table_holds_no_status_or_idempotency_truth(): void
    {
        $columns = Schema::getColumnListing('google_ads_mutations');

        foreach (['status', 'state', 'failure_classification', 'failure_code', 'local_operation_key', 'idempotency_key', 'completed_at'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, $forbidden . ' belongs to the ledger (D8)');
        }

        foreach (['business_google_operation_id', 'kind', 'target_type', 'target_local_id', 'target_resource_name', 'requested_state', 'params', 'dedupe_key', 'actor_user_id'] as $required) {
            $this->assertContains($required, $columns);
        }
    }

    public function test_the_attribution_table_has_exactly_the_contract_columns_and_the_lookup_indexes(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'uid', 'business_id', 'business_location_id', 'contact_id', 'subject_type', 'subject_id',
            'entry_surface', 'touch_role', 'gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign',
            'utm_term', 'utm_content', 'landing_page', 'captured_at', 'recorded_at',
        ], Schema::getColumnListing('lead_attribution_touches'));

        $indexed = collect(DB::select(
            'select index_name, group_concat(column_name order by seq_in_index) as cols from information_schema.statistics where table_schema = ? and table_name = ? group by index_name',
            [DB::getDatabaseName(), 'lead_attribution_touches'],
        ))->pluck('cols')->all();

        foreach (['business_id,contact_id', 'business_id,captured_at', 'business_id,gclid', 'business_id,gbraid', 'business_id,wbraid'] as $expected) {
            $this->assertContains($expected, $indexed, $expected);
        }
    }

    // ---------------------------------------------------------------
    // Absence is NULL, not zero
    // ---------------------------------------------------------------

    public function test_a_missing_conversion_figure_stays_null_and_a_real_zero_stays_zero(): void
    {
        $scope = $this->scope($this->account);

        $absent = GoogleAdsDailyMetric::create($scope + ['level' => 'campaign', 'entity_key' => '1000000001', 'metric_date' => '2026-10-01', 'cost_micros' => 3_600_000]);
        $zero = GoogleAdsDailyMetric::create($scope + ['level' => 'campaign', 'entity_key' => '1000000002', 'metric_date' => '2026-10-01', 'cost_micros' => 3_600_000, 'conversions' => '0.000000', 'conversions_value' => '0.000000']);

        $this->assertNull($absent->fresh()->conversions);
        $this->assertNull($absent->fresh()->conversions_value);
        $this->assertSame('0.000000', $zero->fresh()->conversions);
        $this->assertSame(3_600_000, $absent->fresh()->cost_micros);

        $term = GoogleAdsSearchTerm::create($scope + $this->termAttributes($this->adGroup($this->campaign()), 'x'));
        $this->assertNull($term->fresh()->conversions);
        $this->assertSame(0, $term->fresh()->clicks);
    }

    /** @return array<string, mixed> */
    private function termAttributes(GoogleAdsAdGroup $adGroup, string $term, string $date = '2026-10-01'): array
    {
        return [
            'google_ads_campaign_id' => $adGroup->google_ads_campaign_id, 'google_ads_ad_group_id' => $adGroup->id,
            'search_term' => $term, 'term_hash' => GoogleAdsSearchTerm::hashTerm($term), 'metric_date' => $date,
        ];
    }

    public function test_micros_round_trip_beyond_32_bits(): void
    {
        $metric = GoogleAdsDailyMetric::create($this->scope($this->account) + [
            'level' => 'campaign', 'entity_key' => '1000000001', 'metric_date' => '2026-10-01', 'cost_micros' => 9_007_199_254_740_993,
        ]);

        $this->assertSame(9_007_199_254_740_993, $metric->fresh()->cost_micros);
    }

    // ---------------------------------------------------------------
    // Natural keys make upserts idempotent
    // ---------------------------------------------------------------

    public function test_natural_unique_keys_reject_duplicates(): void
    {
        $campaign = $this->campaign();
        $adGroup = $this->adGroup($campaign);
        $scope = $this->scope($this->account);

        $attempts = [
            'campaign' => fn () => $this->campaign(),
            'ad group' => fn () => $this->adGroup($campaign),
            'keyword' => function () use ($scope, $campaign, $adGroup) {
                $attributes = $scope + [
                    'google_ads_campaign_id' => $campaign->id, 'google_ads_ad_group_id' => $adGroup->id,
                    'external_criterion_id' => '3000000001~1', 'text' => 'photo booth', 'match_type' => 'PHRASE',
                    'status' => 'ENABLED', 'is_negative' => false, 'level' => 'ad_group',
                ];
                GoogleAdsKeyword::create($attributes);
                GoogleAdsKeyword::create($attributes);
            },
            'search term' => function () use ($scope, $adGroup) {
                GoogleAdsSearchTerm::create($scope + $this->termAttributes($adGroup, 'photo booth'));
                GoogleAdsSearchTerm::create($scope + $this->termAttributes($adGroup, 'Photo Booth '));
            },
            'daily metric' => function () use ($scope) {
                $row = $scope + ['level' => 'campaign', 'entity_key' => '1000000001', 'metric_date' => '2026-10-01'];
                GoogleAdsDailyMetric::create($row);
                GoogleAdsDailyMetric::create($row);
            },
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail($name . ' must be unique on its natural key');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Duplicate entry', $e->getMessage(), $name);
            }
        }
    }

    public function test_a_keyword_level_separates_campaign_and_ad_group_criterion_ids(): void
    {
        $campaign = $this->campaign();
        $adGroup = $this->adGroup($campaign);
        $base = $this->scope($this->account) + [
            'google_ads_campaign_id' => $campaign->id, 'external_criterion_id' => '1000000001~5',
            'text' => 'free', 'match_type' => 'BROAD', 'status' => 'ENABLED', 'is_negative' => true,
        ];

        GoogleAdsKeyword::create($base + ['level' => 'campaign']);
        GoogleAdsKeyword::create($base + ['level' => 'ad_group', 'google_ads_ad_group_id' => $adGroup->id]);

        $this->assertSame(2, GoogleAdsKeyword::count());
    }

    public function test_upserting_the_same_window_twice_changes_nothing(): void
    {
        $rows = [];
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $rows[] = $this->scope($this->account) + [
                'level' => 'campaign', 'entity_key' => '1000000001', 'metric_date' => $day,
                'impressions' => 90, 'clicks' => 4, 'interactions' => 4, 'cost_micros' => 3_600_000,
                'conversions' => '1.000000', 'conversions_value' => '150.000000',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        $update = ['impressions', 'clicks', 'interactions', 'cost_micros', 'conversions', 'conversions_value', 'updated_at'];
        $key = ['google_ads_account_id', 'level', 'entity_key', 'metric_date'];

        GoogleAdsDailyMetric::upsert($rows, $key, $update);
        GoogleAdsDailyMetric::upsert($rows, $key, $update);

        $this->assertSame(3, GoogleAdsDailyMetric::count());

        $rows[0]['cost_micros'] = 4_000_000;
        GoogleAdsDailyMetric::upsert($rows, $key, $update);
        $this->assertSame(3, GoogleAdsDailyMetric::count());
        $this->assertSame(4_000_000, GoogleAdsDailyMetric::where('metric_date', '2026-10-01')->value('cost_micros'));
    }

    public function test_one_account_row_per_business(): void
    {
        $this->expectException(QueryException::class);

        GoogleAdsAccount::create([
            'business_id' => $this->business->id,
            'business_google_connection_id' => $this->account->business_google_connection_id,
            'customer_id' => '9999999999',
            'currency_code' => 'USD',
            'time_zone' => 'UTC',
            'selected_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Composite foreign keys
    // ---------------------------------------------------------------

    public function test_a_child_row_cannot_carry_another_business_than_its_account(): void
    {
        [, $other] = $this->adsTenant('Other Co');

        $this->expectException(QueryException::class);

        GoogleAdsCampaign::create([
            'business_id' => $other->id,
            'google_ads_account_id' => $this->account->id,
            'external_campaign_id' => '1000000001', 'name' => 'x', 'status' => 'ENABLED',
        ]);
    }

    public function test_a_keyword_cannot_reference_a_campaign_of_another_account(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $foreignCampaign = $this->campaign($this->accountFor($other), '1000000009');

        $this->expectException(QueryException::class);

        GoogleAdsKeyword::create($this->scope($this->account) + [
            'google_ads_campaign_id' => $foreignCampaign->id,
            'external_criterion_id' => '1~1', 'text' => 'x', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'level' => 'campaign', 'is_negative' => true,
        ]);
    }

    public function test_deleting_an_account_removes_its_facts_but_not_another_accounts(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $otherAccount = $this->accountFor($other);

        $campaign = $this->campaign();
        $this->adGroup($campaign);
        GoogleAdsDailyMetric::create($this->scope($this->account) + ['level' => 'campaign', 'entity_key' => '1000000001', 'metric_date' => '2026-10-01']);
        $this->campaign($otherAccount, '1000000001');

        $this->account->delete();

        foreach (['google_ads_campaigns', 'google_ads_ad_groups', 'google_ads_daily_metrics'] as $table) {
            $this->assertSame(0, DB::table($table)->where('google_ads_account_id', $this->account->id)->count(), $table);
        }
        $this->assertSame(1, DB::table('google_ads_campaigns')->where('google_ads_account_id', $otherAccount->id)->count());
    }

    // ---------------------------------------------------------------
    // Models
    // ---------------------------------------------------------------

    public function test_uids_are_uuids_and_cannot_be_mass_assigned(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $connection = $this->activeAdsConnection($other);

        $account = GoogleAdsAccount::create([
            'uid' => 'attacker-chosen',
            'business_id' => $other->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => '1112223333', 'currency_code' => 'GBP', 'time_zone' => 'Europe/London', 'selected_at' => now(),
        ]);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $account->fresh()->uid);
        $this->assertNotSame('attacker-chosen', $account->fresh()->uid);
        $this->assertTrue(GoogleAdsAccount::query()->where('business_id', $other->id)->where('uid', $account->uid)->exists(), 'route key is the uid');
        $this->assertSame('uid', $account->getRouteKeyName());
    }

    public function test_the_sync_run_trigger_column_and_enum_casts_work(): void
    {
        $run = GoogleAdsSyncRun::create($this->scope($this->account) + [
            'state' => GoogleAdsSyncRunState::Running, 'trigger' => GoogleAdsSyncTrigger::Manual, 'started_at' => now(),
        ]);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $run->uid);
        $this->assertSame(GoogleAdsSyncTrigger::Manual, $run->fresh()->trigger);
        $this->assertSame(0, $run->fresh()->rows_counted);
        $this->assertNull($run->fresh()->failure_code);
        $this->assertSame(1, GoogleAdsSyncRun::where('trigger', 'manual')->count());
    }

    public function test_a_mutation_detail_row_is_one_to_one_with_a_ledger_operation(): void
    {
        $campaign = $this->campaign();
        $operation = app(GoogleAdsOperationLedger::class)->open(
            (int) $this->business->id, GoogleOperationType::AdsCampaignStatusChanged, null, 'Pause campaign',
        );

        $detail = fn () => GoogleAdsMutation::create($this->scope($this->account) + [
            'business_google_operation_id' => $operation->id,
            'kind' => GoogleAdsMutationKind::CampaignStatus,
            'target_type' => 'campaign',
            'target_local_id' => $campaign->id,
            'target_resource_name' => 'customers/1234567890/campaigns/1000000001',
            'requested_state' => 'PAUSED',
            'dedupe_key' => hash('sha256', 'campaign:' . $campaign->id . ':PAUSED'),
        ]);

        $mutation = $detail();

        $this->assertSame(GoogleAdsMutationKind::CampaignStatus, $mutation->fresh()->kind);
        $this->assertNull($mutation->fresh()->params);
        $this->assertSame($operation->id, $mutation->operation->id);

        $this->expectException(QueryException::class);
        $detail();
    }

    // ---------------------------------------------------------------
    // Append-only attribution
    // ---------------------------------------------------------------

    private function touch(array $overrides = []): LeadAttributionTouch
    {
        return LeadAttributionTouch::create(array_merge([
            'business_id' => $this->business->id,
            'subject_type' => LeadAttributionSubjectType::FormSubmission,
            'subject_id' => 1,
            'entry_surface' => LeadAttributionEntrySurface::PublicForm,
            'touch_role' => LeadAttributionTouchRole::First,
            'gclid' => 'Cj0KCQiA_test_click_id',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'photo-booth-rental',
            'landing_page' => '/photo-booth-rental',
            'captured_at' => now()->subDay(),
            'recorded_at' => now(),
        ], $overrides));
    }

    public function test_a_touch_is_inserted_with_a_uuid_and_typed_enums(): void
    {
        $touch = $this->touch();

        $fresh = $touch->fresh();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $fresh->uid);
        $this->assertSame(LeadAttributionTouchRole::First, $fresh->touch_role);
        $this->assertSame(LeadAttributionEntrySurface::PublicForm, $fresh->entry_surface);
        $this->assertNull($fresh->contact_id);
        $this->assertNull($fresh->gbraid);
        $this->assertNull($fresh->business_location_id);
    }

    public function test_a_touch_can_never_be_updated_or_deleted(): void
    {
        $touch = $this->touch();

        try {
            $touch->update(['gclid' => 'rewritten']);
            $this->fail('a touch is append-only');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $touch->utm_source = 'bing';
            $touch->save();
            $this->fail('a touch is append-only');
        } catch (LogicException) {
        }

        try {
            $touch->delete();
            $this->fail('a touch is never deleted');
        } catch (LogicException) {
        }

        $this->assertSame('Cj0KCQiA_test_click_id', $touch->fresh()->gclid);
        $this->assertSame('google', $touch->fresh()->utm_source);
    }

    public function test_first_and_last_are_separate_rows_but_never_duplicate_for_one_event(): void
    {
        $this->touch();
        $this->touch(['touch_role' => LeadAttributionTouchRole::Last, 'gclid' => 'second-click']);

        $this->assertSame(2, LeadAttributionTouch::count());

        $this->expectException(QueryException::class);
        $this->touch(['gclid' => 'duplicate-first']);
    }

    public function test_a_different_conversion_event_gets_its_own_first_touch(): void
    {
        $this->touch(['subject_id' => 1]);
        $this->touch(['subject_id' => 2]);
        $this->touch(['subject_type' => LeadAttributionSubjectType::Appointment, 'subject_id' => 1, 'entry_surface' => LeadAttributionEntrySurface::Booking]);

        $this->assertSame(3, LeadAttributionTouch::count());
    }

    public function test_a_touch_is_removed_with_its_contact_so_click_ids_do_not_outlive_the_lead(): void
    {
        $group = ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Leads', 'status' => true]);
        $contact = Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $this->business->id,
            'group_id' => $group->id, 'phone' => '14155552671', 'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        $this->touch(['contact_id' => $contact->id]);
        $this->touch(['contact_id' => $contact->id, 'subject_id' => 2]);
        $this->touch(['subject_id' => 3]);

        DB::table('contacts')->where('id', $contact->id)->delete();

        $this->assertSame(1, LeadAttributionTouch::count());
        $this->assertNull(LeadAttributionTouch::first()->contact_id);
    }

    public function test_a_touch_cannot_name_a_business_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        $this->touch(['business_id' => 999999999]);
    }
}
