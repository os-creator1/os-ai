<?php

namespace Tests\Feature\MetaAds;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaAdsRequestedState;
use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use App\Models\MetaAdsDailyInsight;
use App\Models\MetaAdsDailyResult;
use App\Models\MetaAdsMutation;
use App\Models\MetaAdsSyncRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §3 / §5 — the normalised schema: money is
 * BIGINT micros, absence is NULL not 0, natural keys make upserts idempotent,
 * composite foreign keys make a cross-Business reference impossible, and the
 * access token is encrypted at rest.
 *
 * CreatesGoogleAdsFixtures is reused ONLY for its tenant factory (adsTenant).
 */
class MetaAdsSchemaTest extends TestCase
{
    use CreatesGoogleAdsFixtures;
    use RefreshDatabase;

    private const TABLES = [
        'business_meta_connections', 'business_meta_operations', 'meta_ads_accounts', 'meta_ads_campaigns',
        'meta_ads_ad_sets', 'meta_ads_ads', 'meta_ads_daily_insights', 'meta_ads_daily_results',
        'meta_ads_sync_runs', 'meta_ads_mutations',
    ];

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private Business $business;

    private MetaAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->adsTenant();
        $this->account = $this->accountFor($this->business);
    }

    private function connectionFor(Business $business, array $overrides = []): BusinessMetaConnection
    {
        return BusinessMetaConnection::create(array_merge([
            'business_id' => $business->id,
            'state' => MetaConnectionState::Active,
            'access_token_encrypted' => 'plain-meta-long-lived-token',
            'token_expires_at' => now()->addDays(60),
            'granted_scopes' => 'ads_read,ads_management',
            'meta_user_id' => '10000000001',
            'meta_user_name' => 'Owner Person',
            'connected_at' => now(),
            'connected_by_user_id' => $business->customer_id,
        ], $overrides));
    }

    private function accountFor(Business $business, string $adAccountId = '123456789'): MetaAdsAccount
    {
        $connection = $this->connectionFor($business);

        return MetaAdsAccount::create([
            'business_id' => $business->id,
            'business_meta_connection_id' => $connection->id,
            'ad_account_id' => $adAccountId,
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'selected_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function scope(MetaAdsAccount $account): array
    {
        return ['business_id' => $account->business_id, 'meta_ads_account_id' => $account->id];
    }

    private function campaign(?MetaAdsAccount $account = null, string $externalId = '6000000001'): MetaAdsCampaign
    {
        return MetaAdsCampaign::create($this->scope($account ?? $this->account) + [
            'external_campaign_id' => $externalId, 'name' => 'Photo Booth Leads', 'status' => 'ACTIVE',
        ]);
    }

    private function adSet(MetaAdsCampaign $campaign, string $externalId = '7000000001'): MetaAdsAdSet
    {
        return MetaAdsAdSet::create([
            'business_id' => $campaign->business_id, 'meta_ads_account_id' => $campaign->meta_ads_account_id,
            'meta_ads_campaign_id' => $campaign->id, 'external_ad_set_id' => $externalId, 'name' => 'Local 25-54', 'status' => 'ACTIVE',
        ]);
    }

    private function ad(MetaAdsAdSet $adSet, string $externalId = '8000000001'): MetaAdsAd
    {
        return MetaAdsAd::create([
            'business_id' => $adSet->business_id, 'meta_ads_account_id' => $adSet->meta_ads_account_id,
            'meta_ads_campaign_id' => $adSet->meta_ads_campaign_id, 'meta_ads_ad_set_id' => $adSet->id,
            'external_ad_id' => $externalId, 'name' => 'Video 1', 'status' => 'ACTIVE',
        ]);
    }

    private function operation(Business $business, MetaOperationType $type = MetaOperationType::CampaignStatusChanged, ?string $key = null): BusinessMetaOperation
    {
        return BusinessMetaOperation::create([
            'business_id' => $business->id,
            'operation_type' => $type,
            'local_operation_key' => $key ?? ('op-' . uniqid('', true)),
            'status' => MetaOperationStatus::Pending,
        ]);
    }

    private function column(string $table, string $name): object
    {
        return DB::selectOne(
            'select data_type as data_type, column_type as column_type, is_nullable as is_nullable, column_default as column_default from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $name],
        );
    }

    // ---------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------

    public function test_every_table_exists_with_the_contract_columns(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $expected = [
            'business_meta_connections' => ['id', 'uid', 'business_id', 'state', 'access_token_encrypted', 'token_expires_at', 'granted_scopes', 'meta_user_id', 'meta_user_name', 'oauth_state_nonce', 'oauth_state_expires_at', 'connected_at', 'disconnected_at', 'revoked_at', 'last_verified_at', 'failure_classification', 'connected_by_user_id', 'lock_version', 'created_at', 'updated_at'],
            'business_meta_operations' => ['id', 'uid', 'business_id', 'operation_type', 'local_operation_key', 'request_fingerprint', 'provider_call_count', 'provider_operation_reference', 'status', 'actor_user_id', 'summary', 'failure_classification', 'started_at', 'completed_at', 'created_at', 'updated_at'],
            'meta_ads_accounts' => ['id', 'uid', 'business_id', 'business_meta_connection_id', 'ad_account_id', 'name', 'currency_code', 'time_zone', 'account_status', 'result_action_type', 'selected_at', 'selected_by_user_id', 'selected_meta_user_id', 'monthly_budget_target_micros', 'target_cost_per_result_micros', 'last_sync_started_at', 'last_successful_sync_at', 'data_through_date', 'last_sync_failure_code', 'sync_claimed_at', 'manual_refresh_requested_at', 'created_at', 'updated_at'],
            'meta_ads_campaigns' => ['id', 'uid', 'business_id', 'meta_ads_account_id', 'external_campaign_id', 'name', 'status', 'effective_status', 'objective', 'daily_budget_minor', 'lifetime_budget_minor', 'budget_remaining_minor', 'start_time', 'stop_time', 'last_synced_at', 'created_at', 'updated_at', 'acquisition_purpose_id'],
            'meta_ads_ad_sets' => ['id', 'uid', 'business_id', 'meta_ads_account_id', 'meta_ads_campaign_id', 'external_ad_set_id', 'name', 'status', 'effective_status', 'daily_budget_minor', 'lifetime_budget_minor', 'optimization_goal', 'bid_strategy', 'targeting_summary', 'reach_7d', 'frequency_7d', 'frequency_window_end', 'last_synced_at', 'created_at', 'updated_at'],
            'meta_ads_ads' => ['id', 'uid', 'business_id', 'meta_ads_account_id', 'meta_ads_campaign_id', 'meta_ads_ad_set_id', 'external_ad_id', 'name', 'status', 'effective_status', 'creative_title', 'creative_body', 'creative_thumbnail_url', 'creative_object_type', 'last_synced_at', 'created_at', 'updated_at'],
            'meta_ads_daily_insights' => ['id', 'business_id', 'meta_ads_account_id', 'level', 'entity_id', 'metric_date', 'spend_micros', 'impressions', 'clicks', 'link_clicks', 'last_synced_at', 'created_at', 'updated_at'],
            'meta_ads_daily_results' => ['id', 'business_id', 'meta_ads_account_id', 'level', 'entity_id', 'metric_date', 'action_type', 'results', 'result_value', 'last_synced_at', 'created_at', 'updated_at'],
            'meta_ads_sync_runs' => ['id', 'uid', 'business_id', 'meta_ads_account_id', 'state', 'trigger', 'scope', 'started_at', 'completed_at', 'data_through_date', 'rows_counted', 'failure_code', 'business_meta_operation_id', 'created_at', 'updated_at'],
            'meta_ads_mutations' => ['id', 'business_id', 'meta_ads_account_id', 'business_meta_operation_id', 'target_type', 'target_local_id', 'requested_state', 'dedupe_key', 'actor_user_id', 'created_at', 'updated_at'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing($table), $table);
        }
    }

    public function test_every_index_and_constraint_name_fits_mysql_and_follows_the_prefix_convention(): void
    {
        $in = implode(',', array_fill(0, count(self::TABLES), '?'));
        $database = DB::getDatabaseName();

        $names = collect(DB::select(
            'select distinct index_name as name from information_schema.statistics where table_schema = ? and table_name in (' . $in . ')',
            array_merge([$database], self::TABLES),
        ))->pluck('name')->merge(collect(DB::select(
            'select constraint_name as name from information_schema.table_constraints where table_schema = ? and table_name in (' . $in . ')',
            array_merge([$database], self::TABLES),
        ))->pluck('name'))->unique();

        $this->assertGreaterThan(40, $names->count());

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }

        foreach ($names->reject(fn ($n) => $n === 'PRIMARY' || str_ends_with($n, '_uid_unique')) as $name) {
            $this->assertMatchesRegularExpression('/\A(bmc|bmo|mads)_/', $name, $name);
        }
    }

    public function test_money_is_bigint_and_results_are_nullable_decimal_20_6(): void
    {
        foreach ([
            ['meta_ads_accounts', 'monthly_budget_target_micros'],
            ['meta_ads_accounts', 'target_cost_per_result_micros'],
            ['meta_ads_campaigns', 'daily_budget_minor'],
            ['meta_ads_campaigns', 'lifetime_budget_minor'],
            ['meta_ads_campaigns', 'budget_remaining_minor'],
            ['meta_ads_ad_sets', 'daily_budget_minor'],
            ['meta_ads_daily_insights', 'spend_micros'],
        ] as [$table, $name]) {
            $this->assertSame('bigint', strtolower($this->column($table, $name)->data_type), "$table.$name");
        }

        $this->assertSame('NO', $this->column('meta_ads_daily_insights', 'spend_micros')->is_nullable);
        $this->assertSame('YES', $this->column('meta_ads_daily_insights', 'link_clicks')->is_nullable, 'absence is NULL');
        $this->assertSame('YES', $this->column('meta_ads_accounts', 'monthly_budget_target_micros')->is_nullable);
        $this->assertSame('YES', $this->column('meta_ads_accounts', 'result_action_type')->is_nullable);
        $this->assertSame('NO', $this->column('meta_ads_accounts', 'currency_code')->is_nullable);
        $this->assertSame('NO', $this->column('meta_ads_accounts', 'time_zone')->is_nullable);
        $this->assertSame('YES', $this->column('meta_ads_accounts', 'selected_at')->is_nullable);

        $results = $this->column('meta_ads_daily_results', 'results');
        $this->assertSame('decimal(20,6)', strtolower($results->column_type));
        $value = $this->column('meta_ads_daily_results', 'result_value');
        $this->assertSame('decimal(20,6)', strtolower($value->column_type));
        $this->assertSame('YES', $value->is_nullable);
    }

    public function test_operation_type_column_is_40_wide_and_the_ledger_has_no_location_column(): void
    {
        $this->assertSame('varchar(40)', strtolower($this->column('business_meta_operations', 'operation_type')->column_type));
        $this->assertNotContains('business_google_location_id', Schema::getColumnListing('business_meta_operations'));
        $this->assertSame('0', (string) $this->column('business_meta_operations', 'provider_call_count')->column_default);

        // No foreign key from the ledger to businesses: a disconnect must not erase its own audit row.
        $fks = DB::select(
            'select constraint_name as name from information_schema.referential_constraints where constraint_schema = ? and table_name = ? and referenced_table_name = ?',
            [DB::getDatabaseName(), 'business_meta_operations', 'businesses'],
        );
        $this->assertSame([], $fks);
    }

    public function test_the_mutation_detail_table_holds_no_status_or_idempotency_truth(): void
    {
        $columns = Schema::getColumnListing('meta_ads_mutations');

        foreach (['status', 'state', 'failure_classification', 'failure_code', 'local_operation_key', 'idempotency_key', 'completed_at'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, $forbidden . ' belongs to the ledger');
        }
    }

    // ---------------------------------------------------------------
    // Connection: encryption, hidden, uid, transitions
    // ---------------------------------------------------------------

    public function test_the_access_token_is_encrypted_at_rest_and_round_trips(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $connection = $this->connectionFor($other, ['access_token_encrypted' => 'EAAB-secret-long-lived-token']);

        $raw = DB::table('business_meta_connections')->where('id', $connection->id)->value('access_token_encrypted');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('EAAB-secret-long-lived-token', $raw);
        $this->assertNotSame('EAAB-secret-long-lived-token', $raw);
        $this->assertSame('EAAB-secret-long-lived-token', $connection->fresh()->access_token_encrypted);
        $this->assertTrue($connection->fresh()->hasStoredAuthorization());
    }

    public function test_the_token_and_state_nonce_never_serialise(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $connection = $this->connectionFor($other, ['oauth_state_nonce' => 'nonce-value', 'oauth_state_expires_at' => now()->addMinutes(10)]);

        $array = $connection->fresh()->toArray();
        $this->assertArrayNotHasKey('access_token_encrypted', $array);
        $this->assertArrayNotHasKey('oauth_state_nonce', $array);
        $this->assertStringNotContainsString('plain-meta-long-lived-token', $connection->fresh()->toJson());
        $this->assertStringNotContainsString('nonce-value', $connection->fresh()->toJson());
    }

    public function test_connection_uid_is_a_uuid_defaults_to_pending_and_casts_state(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $connection = BusinessMetaConnection::create(['uid' => 'attacker-chosen', 'business_id' => $other->id]);
        $fresh = $connection->fresh();

        $this->assertNotSame('attacker-chosen', $fresh->uid, 'uid is not mass-assignable');
        $this->assertMatchesRegularExpression(self::UUID, $fresh->uid);
        $this->assertSame(MetaConnectionState::Pending, $fresh->state);
        $this->assertNull($fresh->access_token_encrypted);
        $this->assertFalse($fresh->hasStoredAuthorization());
        $this->assertFalse($fresh->isActive());
        $this->assertSame(0, $fresh->lock_version);
    }

    public function test_one_connection_per_business_and_nonces_are_unique(): void
    {
        try {
            $this->connectionFor($this->business);
            $this->fail('a Business has exactly one Meta connection');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        }

        [, $b] = $this->adsTenant('B Co');
        [, $c] = $this->adsTenant('C Co');
        $this->connectionFor($b, ['oauth_state_nonce' => 'same-nonce']);

        $this->expectException(QueryException::class);
        $this->connectionFor($c, ['oauth_state_nonce' => 'same-nonce']);
    }

    // ---------------------------------------------------------------
    // Ledger
    // ---------------------------------------------------------------

    public function test_ledger_keys_are_unique_and_the_provider_reference_is_unique_only_when_set(): void
    {
        $this->operation($this->business, MetaOperationType::MetaAdsSync, 'key-1');

        try {
            $this->operation($this->business, MetaOperationType::MetaAdsSync, 'key-1');
            $this->fail('local_operation_key must be unique');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        }

        // Many rows without a provider reference are fine (NULL is not a duplicate).
        $this->operation($this->business, MetaOperationType::MetaAdsSync, 'key-2');
        $this->operation($this->business, MetaOperationType::MetaAdsSync, 'key-3');
        $this->assertSame(3, BusinessMetaOperation::count());

        $row = fn (string $key, string $reference) => BusinessMetaOperation::create([
            'business_id' => $this->business->id, 'operation_type' => MetaOperationType::CampaignStatusChanged,
            'local_operation_key' => $key, 'provider_operation_reference' => $reference, 'status' => MetaOperationStatus::Succeeded,
        ]);

        $row('key-4', 'ref-1');

        $this->expectException(QueryException::class);
        $row('key-5', 'ref-1');
    }

    public function test_ledger_casts_and_defaults(): void
    {
        $operation = $this->operation($this->business, MetaOperationType::AccountSelected)->fresh();

        $this->assertMatchesRegularExpression(self::UUID, $operation->uid);
        $this->assertSame(MetaOperationType::AccountSelected, $operation->operation_type);
        $this->assertSame(MetaOperationStatus::Pending, $operation->status);
        $this->assertSame(0, $operation->provider_call_count);
        $this->assertNull($operation->provider_operation_reference);
    }

    public function test_ledger_survives_deleting_its_business_connection_and_account(): void
    {
        $operation = $this->operation($this->business);

        $this->account->connection->delete();

        $this->assertSame(1, BusinessMetaOperation::whereKey($operation->id)->count(), 'the audit row is retained');
    }

    // ---------------------------------------------------------------
    // Absence is NULL, not zero
    // ---------------------------------------------------------------

    public function test_missing_figures_stay_null_and_real_zeros_stay_zero(): void
    {
        $scope = $this->scope($this->account);
        $campaign = $this->campaign();

        $absent = MetaAdsDailyInsight::create($scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01', 'spend_micros' => 3_600_000]);
        $zero = MetaAdsDailyInsight::create($scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-02', 'spend_micros' => 0, 'link_clicks' => 0]);

        $this->assertNull($absent->fresh()->link_clicks);
        $this->assertSame(0, $zero->fresh()->link_clicks);
        $this->assertSame(3_600_000, $absent->fresh()->spend_micros);
        $this->assertSame(0, $absent->fresh()->clicks);

        $noValue = MetaAdsDailyResult::create($scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01', 'action_type' => 'lead', 'results' => '4.000000']);
        $this->assertNull($noValue->fresh()->result_value);
        $this->assertSame('4.000000', $noValue->fresh()->results);

        $account = $this->account->fresh();
        $this->assertNull($account->monthly_budget_target_micros);
        $this->assertNull($account->target_cost_per_result_micros);
        $this->assertNull($account->result_action_type, 'no chosen result type means results are unavailable');

        $c = $campaign->fresh();
        $this->assertNull($c->daily_budget_minor);
        $this->assertNull($c->lifetime_budget_minor);
        $this->assertNull($c->stop_time);
    }

    public function test_micros_and_budgets_round_trip_beyond_32_bits(): void
    {
        $insight = MetaAdsDailyInsight::create($this->scope($this->account) + [
            'level' => 'campaign', 'entity_id' => $this->campaign()->id, 'metric_date' => '2026-10-01', 'spend_micros' => 9_007_199_254_740_993,
        ]);
        $this->assertSame(9_007_199_254_740_993, $insight->fresh()->spend_micros);

        $this->account->update(['monthly_budget_target_micros' => 5_000_000_000_000]);
        $this->assertSame(5_000_000_000_000, $this->account->fresh()->monthly_budget_target_micros);
    }

    public function test_ad_set_frequency_and_reach_are_the_ad_sets_own_columns(): void
    {
        $adSet = $this->adSet($this->campaign());
        $adSet->update(['reach_7d' => 12345, 'frequency_7d' => '3.2500', 'frequency_window_end' => '2026-10-03']);

        $fresh = $adSet->fresh();
        $this->assertSame(12345, $fresh->reach_7d);
        $this->assertSame('3.2500', $fresh->frequency_7d);
        $this->assertSame('2026-10-03', $fresh->frequency_window_end->toDateString());
        $this->assertNull($this->adSet($this->campaign(null, '6000000002'), '7000000002')->fresh()->frequency_7d);
    }

    // ---------------------------------------------------------------
    // Natural keys
    // ---------------------------------------------------------------

    public function test_natural_unique_keys_reject_duplicates(): void
    {
        $campaign = $this->campaign();
        $adSet = $this->adSet($campaign);
        $this->ad($adSet);
        $scope = $this->scope($this->account);

        $attempts = [
            'campaign' => fn () => $this->campaign(),
            'ad set' => fn () => $this->adSet($campaign),
            'ad' => fn () => $this->ad($adSet),
            'daily insight' => function () use ($scope, $campaign) {
                $row = $scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01'];
                MetaAdsDailyInsight::create($row);
                MetaAdsDailyInsight::create($row);
            },
            'daily result' => function () use ($scope, $campaign) {
                $row = $scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01', 'action_type' => 'lead', 'results' => 1];
                MetaAdsDailyResult::create($row);
                MetaAdsDailyResult::create($row);
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

    public function test_a_result_row_is_per_action_type_and_per_level(): void
    {
        $campaign = $this->campaign();
        $base = $this->scope($this->account) + ['entity_id' => $campaign->id, 'metric_date' => '2026-10-01', 'results' => 2];

        MetaAdsDailyResult::create($base + ['level' => 'campaign', 'action_type' => 'lead']);
        MetaAdsDailyResult::create($base + ['level' => 'campaign', 'action_type' => 'link_click']);
        MetaAdsDailyResult::create($base + ['level' => 'ad_set', 'action_type' => 'lead']);

        $this->assertSame(3, MetaAdsDailyResult::count());
        $this->assertSame(1, MetaAdsDailyResult::forAccount($this->account->id)->atLevel(MetaAdsLevel::Campaign)->ofActionType('lead')->count());
        $this->assertSame(MetaAdsLevel::AdSet, MetaAdsDailyResult::where('level', 'ad_set')->first()->level);
    }

    public function test_upserting_the_same_window_twice_changes_nothing(): void
    {
        $campaign = $this->campaign();
        $rows = [];
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $rows[] = $this->scope($this->account) + [
                'level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => $day,
                'spend_micros' => 3_600_000, 'impressions' => 90, 'clicks' => 4, 'link_clicks' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        $update = ['spend_micros', 'impressions', 'clicks', 'link_clicks', 'updated_at'];
        $key = ['meta_ads_account_id', 'level', 'entity_id', 'metric_date'];

        MetaAdsDailyInsight::upsert($rows, $key, $update);
        MetaAdsDailyInsight::upsert($rows, $key, $update);
        $this->assertSame(3, MetaAdsDailyInsight::count());

        $rows[0]['spend_micros'] = 4_000_000;
        MetaAdsDailyInsight::upsert($rows, $key, $update);
        $this->assertSame(3, MetaAdsDailyInsight::count());
        $this->assertSame(4_000_000, MetaAdsDailyInsight::where('metric_date', '2026-10-01')->value('spend_micros'));
    }

    public function test_one_account_row_per_business(): void
    {
        $this->expectException(QueryException::class);

        MetaAdsAccount::create([
            'business_id' => $this->business->id,
            'business_meta_connection_id' => $this->account->business_meta_connection_id,
            'ad_account_id' => '999999999', 'currency_code' => 'USD', 'time_zone' => 'UTC',
        ]);
    }

    // ---------------------------------------------------------------
    // Composite foreign keys / tenant isolation
    // ---------------------------------------------------------------

    public function test_an_account_cannot_bind_another_businesses_connection(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $foreignConnection = $this->connectionFor($other);

        $this->expectException(QueryException::class);

        MetaAdsAccount::create([
            'business_id' => $this->business->id,
            'business_meta_connection_id' => $foreignConnection->id,
            'ad_account_id' => '555', 'currency_code' => 'USD', 'time_zone' => 'UTC',
        ]);
    }

    public function test_an_account_cannot_exist_without_a_connection(): void
    {
        [, $other] = $this->adsTenant('Other Co');

        $this->expectException(QueryException::class);

        MetaAdsAccount::create([
            'business_id' => $other->id, 'business_meta_connection_id' => 99999999,
            'ad_account_id' => '555', 'currency_code' => 'USD', 'time_zone' => 'UTC',
        ]);
    }

    public function test_a_child_row_cannot_carry_another_business_than_its_account(): void
    {
        [, $other] = $this->adsTenant('Other Co');

        $this->expectException(QueryException::class);

        MetaAdsCampaign::create([
            'business_id' => $other->id, 'meta_ads_account_id' => $this->account->id,
            'external_campaign_id' => '6000000001', 'name' => 'x', 'status' => 'ACTIVE',
        ]);
    }

    public function test_every_account_child_table_enforces_the_business_account_pair(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $campaign = $this->campaign();
        $operation = $this->operation($this->business);

        $foreign = ['business_id' => $other->id, 'meta_ads_account_id' => $this->account->id];

        $attempts = [
            'insight' => fn () => MetaAdsDailyInsight::create($foreign + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01']),
            'result' => fn () => MetaAdsDailyResult::create($foreign + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01', 'action_type' => 'lead', 'results' => 1]),
            'sync run' => fn () => MetaAdsSyncRun::create($foreign + ['state' => 'running', 'trigger' => 'manual']),
            'mutation' => fn () => MetaAdsMutation::create($foreign + [
                'business_meta_operation_id' => $operation->id, 'target_type' => 'campaign', 'target_local_id' => $campaign->id,
                'requested_state' => 'paused', 'dedupe_key' => 'd',
            ]),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail($name . ' must not accept a Business that is not its account\'s');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()), $name);
            }
        }
    }

    public function test_an_ad_set_and_ad_cannot_reference_another_accounts_parent(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $foreignCampaign = $this->campaign($this->accountFor($other), '6000000009');
        $foreignAdSet = $this->adSet($foreignCampaign, '7000000009');
        $ownCampaign = $this->campaign();
        $ownAdSet = $this->adSet($ownCampaign);

        try {
            MetaAdsAdSet::create($this->scope($this->account) + [
                'meta_ads_campaign_id' => $foreignCampaign->id, 'external_ad_set_id' => '1', 'name' => 'x', 'status' => 'ACTIVE',
            ]);
            $this->fail('ad set must not reference a campaign of another account');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        }

        try {
            MetaAdsAd::create($this->scope($this->account) + [
                'meta_ads_campaign_id' => $ownCampaign->id, 'meta_ads_ad_set_id' => $foreignAdSet->id,
                'external_ad_id' => '1', 'name' => 'x', 'status' => 'ACTIVE',
            ]);
            $this->fail('ad must not reference an ad set of another account');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        }

        $this->assertSame($ownAdSet->id, $this->ad($ownAdSet)->meta_ads_ad_set_id);
    }

    // ---------------------------------------------------------------
    // Cascades / orphans
    // ---------------------------------------------------------------

    public function test_deleting_an_account_removes_its_facts_but_not_another_accounts(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $otherAccount = $this->accountFor($other);

        $campaign = $this->campaign();
        $adSet = $this->adSet($campaign);
        $this->ad($adSet);
        $scope = $this->scope($this->account);
        MetaAdsDailyInsight::create($scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01']);
        MetaAdsDailyResult::create($scope + ['level' => 'campaign', 'entity_id' => $campaign->id, 'metric_date' => '2026-10-01', 'action_type' => 'lead', 'results' => 1]);
        MetaAdsSyncRun::create($scope + ['state' => 'running', 'trigger' => 'manual']);
        $this->campaign($otherAccount, '6000000001');

        $this->account->delete();

        foreach (['meta_ads_campaigns', 'meta_ads_ad_sets', 'meta_ads_ads', 'meta_ads_daily_insights', 'meta_ads_daily_results', 'meta_ads_sync_runs'] as $table) {
            $this->assertSame(0, DB::table($table)->where('meta_ads_account_id', $this->account->id)->count(), $table);
        }
        $this->assertSame(1, DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $otherAccount->id)->count());
    }

    public function test_deleting_a_campaign_cascades_to_its_ad_sets_and_ads(): void
    {
        $campaign = $this->campaign();
        $this->ad($this->adSet($campaign));

        $campaign->delete();

        $this->assertSame(0, MetaAdsAdSet::count());
        $this->assertSame(0, MetaAdsAd::count());
    }

    public function test_deleting_a_connection_cascades_to_its_account_and_facts(): void
    {
        $this->campaign();

        $this->account->connection->delete();

        $this->assertSame(0, MetaAdsAccount::count());
        $this->assertSame(0, MetaAdsCampaign::count());
    }

    public function test_deleting_a_business_removes_its_connection_and_account(): void
    {
        $businessId = $this->business->id;

        DB::table('businesses')->where('id', $businessId)->delete();

        $this->assertSame(0, BusinessMetaConnection::where('business_id', $businessId)->count());
        $this->assertSame(0, MetaAdsAccount::where('business_id', $businessId)->count());
    }

    public function test_deleting_the_selecting_user_nulls_the_reference(): void
    {
        $user = User::create([
            'first_name' => 'Selector', 'last_name' => 'Person', 'email' => 'selector' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->account->update(['selected_by_user_id' => $user->id]);
        $this->assertSame($user->id, $this->account->fresh()->selected_by_user_id);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull($this->account->fresh()->selected_by_user_id);
    }

    public function test_deleting_an_operation_removes_its_mutation_detail_but_only_nulls_a_sync_run_link(): void
    {
        $campaign = $this->campaign();
        $operation = $this->operation($this->business);
        $scope = $this->scope($this->account);

        $mutation = MetaAdsMutation::create($scope + [
            'business_meta_operation_id' => $operation->id, 'target_type' => MetaAdsMutationTargetType::Campaign,
            'target_local_id' => $campaign->id, 'requested_state' => MetaAdsRequestedState::Paused, 'dedupe_key' => 'abc',
        ]);
        $run = MetaAdsSyncRun::create($scope + [
            'state' => MetaAdsSyncRunState::Succeeded, 'trigger' => MetaAdsSyncTrigger::Scheduled,
            'business_meta_operation_id' => $operation->id,
        ]);

        $operation->delete();

        $this->assertNull(MetaAdsMutation::find($mutation->id));
        $this->assertNull($run->fresh()->business_meta_operation_id);
    }

    // ---------------------------------------------------------------
    // Models
    // ---------------------------------------------------------------

    public function test_uids_are_uuids_and_cannot_be_mass_assigned(): void
    {
        [, $other] = $this->adsTenant('Other Co');
        $connection = $this->connectionFor($other);

        $account = MetaAdsAccount::create([
            'uid' => 'attacker-chosen', 'business_id' => $other->id, 'business_meta_connection_id' => $connection->id,
            'ad_account_id' => '111', 'currency_code' => 'GBP', 'time_zone' => 'Europe/London',
        ]);

        $this->assertMatchesRegularExpression(self::UUID, $account->fresh()->uid);
        $this->assertNotSame('attacker-chosen', $account->fresh()->uid);
        $this->assertSame('uid', $account->getRouteKeyName());

        foreach ([$this->campaign($account, '1'), $this->adSet($this->campaign($account, '2'), '3')] as $row) {
            $this->assertMatchesRegularExpression(self::UUID, $row->fresh()->uid);
        }
    }

    public function test_the_sync_run_trigger_column_and_enum_casts_work(): void
    {
        $run = MetaAdsSyncRun::create($this->scope($this->account) + [
            'state' => MetaAdsSyncRunState::Running, 'trigger' => MetaAdsSyncTrigger::Manual, 'started_at' => now(),
        ]);

        $fresh = $run->fresh();
        $this->assertMatchesRegularExpression(self::UUID, $fresh->uid);
        $this->assertSame(MetaAdsSyncTrigger::Manual, $fresh->trigger);
        $this->assertSame(MetaAdsSyncRunState::Running, $fresh->state);
        $this->assertSame(0, $fresh->rows_counted);
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->data_through_date);
        $this->assertSame(1, MetaAdsSyncRun::live()->forAccount($this->account->id)->count());
        $this->assertSame(1, MetaAdsSyncRun::where('trigger', 'manual')->count());
    }

    public function test_a_mutation_detail_row_is_one_to_one_with_a_ledger_operation(): void
    {
        $campaign = $this->campaign();
        $operation = $this->operation($this->business);

        $detail = fn () => MetaAdsMutation::create($this->scope($this->account) + [
            'business_meta_operation_id' => $operation->id, 'target_type' => MetaAdsMutationTargetType::Campaign,
            'target_local_id' => $campaign->id, 'requested_state' => MetaAdsRequestedState::Paused, 'dedupe_key' => 'abc',
        ]);

        $mutation = $detail();
        $fresh = $mutation->fresh();

        $this->assertSame(MetaAdsMutationTargetType::Campaign, $fresh->target_type);
        $this->assertSame(MetaAdsRequestedState::Paused, $fresh->requested_state);
        $this->assertSame($operation->id, $fresh->operation->id);

        try {
            $detail();
            $this->fail('one detail row per ledger operation');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        }
    }

    public function test_relationships_resolve(): void
    {
        $campaign = $this->campaign();
        $adSet = $this->adSet($campaign);
        $ad = $this->ad($adSet);

        $this->assertSame($this->account->id, $campaign->account->id);
        $this->assertSame($campaign->id, $adSet->campaign->id);
        $this->assertSame($adSet->id, $ad->adSet->id);
        $this->assertSame($campaign->id, $ad->campaign->id);
        $this->assertSame([$adSet->id], $campaign->adSets->pluck('id')->all());
        $this->assertSame([$ad->id], $adSet->ads->pluck('id')->all());
        $this->assertSame($this->account->id, $this->account->connection->account->id);
        $this->assertTrue($this->account->isSelected());
        $this->assertSame(1, MetaAdsAccount::selected()->forBusiness($this->business->id)->count());
        $this->assertSame($this->business->id, $this->account->connection->business->id);
    }
}
