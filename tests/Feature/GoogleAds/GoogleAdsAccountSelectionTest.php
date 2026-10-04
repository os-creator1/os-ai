<?php

namespace Tests\Feature\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsManagedClient;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsAccountSelectionException;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsAccountDirectory;
use App\Library\GoogleAds\GoogleAdsAccountSelector;
use App\Library\GoogleAds\PhotoBoothFixture as P;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsDailyMetric;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsSearchTerm;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionMethod;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §3 — candidates are derived SERVER-SIDE,
 * selection is explicit and fails CLOSED, and nothing the client posts but a
 * customer id is ever trusted.
 */
class GoogleAdsAccountSelectionTest extends TestCase
{
    use CreatesGoogleAdsFixtures;
    use RefreshDatabase;

    private Business $business;

    private int $actorId;

    private BusinessGoogleConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeAds();

        [$customer, $this->business] = $this->adsTenant();
        $this->actorId = (int) $customer->user_id;
        $this->connection = $this->activeAdsConnection($this->business);
    }

    private function selector(): GoogleAdsAccountSelector
    {
        return app(GoogleAdsAccountSelector::class);
    }

    private function operation(GoogleOperationType $type, ?Business $business = null): ?BusinessGoogleOperation
    {
        return BusinessGoogleOperation::query()
            ->where('business_id', ($business ?? $this->business)->id)
            ->where('operation_type', $type->value)
            ->latest('id')
            ->first();
    }

    private function selectionReason(callable $attempt): string
    {
        try {
            $attempt();
        } catch (GoogleAdsAccountSelectionException $e) {
            return $e->reason;
        }

        $this->fail('expected the selection to be refused');
    }

    // ---------------------------------------------------------------
    // Candidates
    // ---------------------------------------------------------------

    public function test_candidates_are_derived_from_the_provider_and_nothing_is_auto_selected(): void
    {
        $candidates = app(GoogleAdsAccountDirectory::class)->candidates($this->business, $this->connection, $this->actorId);

        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->customerId] = $candidate;
        }

        // Sorted by name; hidden and canceled customers never appear.
        $this->assertSame(
            [P::MANAGER_ID, P::SECOND_MANAGED_CUSTOMER_ID, P::DIRECT_CUSTOMER_ID, P::SUB_MANAGER_ID, P::CUSTOMER_ID],
            array_map(fn ($c) => $c->customerId, $candidates),
        );
        $this->assertArrayNotHasKey(P::HIDDEN_CUSTOMER_ID, $byId);
        $this->assertArrayNotHasKey(P::CANCELED_CUSTOMER_ID, $byId);

        // The real account is reached through the manager: login id derived.
        $account = $byId[P::CUSTOMER_ID];
        $this->assertSame(P::MANAGER_ID, $account->loginCustomerId);
        $this->assertSame('USD', $account->currencyCode);
        $this->assertSame('America/New_York', $account->timeZone);
        $this->assertTrue($account->isTest);
        $this->assertTrue($account->isSelectable());

        // Direct access needs no login id.
        $this->assertNull($byId[P::DIRECT_CUSTOMER_ID]->loginCustomerId);
        $this->assertSame('GBP', $byId[P::DIRECT_CUSTOMER_ID]->currencyCode);

        // Managers (and sub-managers) are listed but not selectable.
        $this->assertTrue($byId[P::MANAGER_ID]->isManager);
        $this->assertFalse($byId[P::MANAGER_ID]->isSelectable());
        $this->assertFalse($byId[P::SUB_MANAGER_ID]->isSelectable());

        $this->assertSame(0, GoogleAdsAccount::count(), 'listing candidates never selects anything');

        // Customer clients were read AS the manager.
        $managerCall = $this->fakeAds->callsTo('managerClients')[0];
        $this->assertSame(P::MANAGER_ID, $managerCall['customer_id']);
        $this->assertSame(P::MANAGER_ID, $managerCall['login_customer_id']);
    }

    public function test_listing_is_one_budget_accounted_ledger_operation(): void
    {
        app(GoogleAdsAccountDirectory::class)->candidates($this->business, $this->connection, $this->actorId);

        $op = $this->operation(GoogleOperationType::AdsAccountsListed);
        $this->assertSame(GoogleOperationStatus::Succeeded, $op->status);
        $this->assertSame($this->actorId, (int) $op->actor_user_id);
        // token refresh + listAccessibleCustomers + 2 customer details + 1 manager listing
        $this->assertSame(5, (int) $op->provider_call_count);
    }

    public function test_a_customer_google_refuses_is_skipped_but_other_failures_propagate(): void
    {
        // The manager (first accessible) is refused: it and its clients drop out.
        $this->fakeAds->failNext('customerDetails', GoogleAdsProviderException::accessDenied());

        $ids = array_map(
            fn ($c) => $c->customerId,
            app(GoogleAdsAccountDirectory::class)->candidates($this->business, $this->connection),
        );
        $this->assertSame([P::DIRECT_CUSTOMER_ID], $ids);

        $this->fakeAds->failNext('listAccessibleCustomers', GoogleAdsProviderException::rateLimited());

        try {
            app(GoogleAdsAccountDirectory::class)->candidates($this->business, $this->connection);
            $this->fail('a rate limit must propagate');
        } catch (GoogleAdsProviderException $e) {
            $this->assertTrue($e->isDeferrable());
        }

        $this->assertSame(GoogleOperationStatus::Deferred, $this->operation(GoogleOperationType::AdsAccountsListed)->status);
    }

    public function test_the_directory_refuses_another_business_or_product(): void
    {
        [, $other] = $this->adsTenant('Other Co');

        try {
            app(GoogleAdsAccountDirectory::class)->candidates($other, $this->connection);
            $this->fail('a Business may only list through its own connection');
        } catch (LogicException) {
            $this->assertSame(0, $this->fakeAds->callCount());
        }

        $foreign = BusinessGoogleConnection::create([
            'business_id' => $other->id,
            'product' => 'business_profile',
            'state' => GoogleConnectionState::Active,
        ]);

        $this->expectException(LogicException::class);
        app(GoogleAdsAccountDirectory::class)->candidates($other, $foreign);
    }

    // ---------------------------------------------------------------
    // Explicit selection
    // ---------------------------------------------------------------

    public function test_explicit_selection_derives_login_currency_and_timezone_server_side(): void
    {
        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);

        $this->assertSame((int) $this->business->id, (int) $account->business_id);
        $this->assertSame((int) $this->connection->id, (int) $account->business_google_connection_id);
        $this->assertSame(P::CUSTOMER_ID, $account->customer_id);
        $this->assertSame(P::MANAGER_ID, $account->login_customer_id);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame('America/New_York', $account->time_zone);
        $this->assertSame('Snap Booth Co', $account->descriptive_name);
        $this->assertTrue($account->is_test_account);
        $this->assertSame($this->actorId, (int) $account->selected_by_user_id);
        $this->assertNotNull($account->selected_at);
        $this->assertNull($account->monthly_budget_target_micros);
        $this->assertNull($account->last_successful_sync_at);

        $this->assertSame(GoogleOperationStatus::Succeeded, $this->operation(GoogleOperationType::AdsAccountSelected)->status);
        $this->assertSame(GoogleOperationStatus::Succeeded, $this->operation(GoogleOperationType::AdsAccountsListed)->status);
    }

    public function test_the_selector_accepts_nothing_but_a_customer_id_from_the_caller(): void
    {
        $parameters = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionMethod(GoogleAdsAccountSelector::class, 'select'))->getParameters(),
        );

        $this->assertSame(['business', 'actorUserId', 'customerId'], $parameters, 'no login id, currency or time zone can be posted');
    }

    public function test_a_hyphenated_customer_id_is_normalised(): void
    {
        $account = $this->selector()->select($this->business, $this->actorId, '123-456-7890');

        $this->assertSame(P::CUSTOMER_ID, $account->customer_id);
    }

    public function test_malformed_customer_ids_are_refused_before_any_provider_call(): void
    {
        foreach (['', '123', 'abcdefghij', "1234567890'; DROP TABLE x", '12345678901'] as $bad) {
            $this->assertSame(
                GoogleAdsAccountSelectionException::INVALID_CUSTOMER_ID,
                $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, $bad)),
                $bad,
            );
        }

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame(0, GoogleAdsAccount::count());
    }

    public function test_an_id_that_is_not_in_the_fresh_candidate_set_is_refused_and_selects_nothing(): void
    {
        // Syntactically perfect, but not reachable by this connection.
        $this->assertSame(
            GoogleAdsAccountSelectionException::NOT_A_CANDIDATE,
            $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, '2223334444')),
        );

        // Hidden and canceled customers are not candidates either.
        foreach ([P::HIDDEN_CUSTOMER_ID, P::CANCELED_CUSTOMER_ID] as $id) {
            $this->assertSame(
                GoogleAdsAccountSelectionException::NOT_A_CANDIDATE,
                $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, $id)),
            );
        }

        $this->assertSame(0, GoogleAdsAccount::count());
        $this->assertNull($this->operation(GoogleOperationType::AdsAccountSelected), 'a refused selection opens no selection operation');
    }

    public function test_a_stale_candidate_is_refused_because_the_set_is_re_derived_on_every_select(): void
    {
        $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        GoogleAdsAccount::query()->delete();

        // Google no longer exposes the client: a candidate list from an earlier render proves nothing.
        $this->fakeAds->withManagerClients(P::MANAGER_ID, array_values(array_filter(
            (new P(self::FIXTURE_TODAY))->managerClients(),
            fn (GoogleAdsManagedClient $c) => $c->customerId !== P::CUSTOMER_ID,
        )));

        $this->assertSame(
            GoogleAdsAccountSelectionException::NOT_A_CANDIDATE,
            $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID)),
        );
        $this->assertSame(0, GoogleAdsAccount::count());
    }

    public function test_managers_are_not_selectable(): void
    {
        foreach ([P::MANAGER_ID, P::SUB_MANAGER_ID] as $manager) {
            $this->assertSame(
                GoogleAdsAccountSelectionException::MANAGER_NOT_SELECTABLE,
                $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, $manager)),
            );
        }

        $this->assertSame(0, GoogleAdsAccount::count());
    }

    public function test_an_inactive_or_absent_connection_cannot_select(): void
    {
        foreach ([GoogleConnectionState::Pending, GoogleConnectionState::Revoked, GoogleConnectionState::Disconnected] as $state) {
            DB::table('business_google_connections')->where('id', $this->connection->id)->update(['state' => $state->value]);

            $this->assertSame(
                GoogleAdsAccountSelectionException::NOT_CONNECTED,
                $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID)),
                $state->value,
            );
        }

        DB::table('business_google_connections')->where('id', $this->connection->id)->delete();
        $this->assertSame(
            GoogleAdsAccountSelectionException::NOT_CONNECTED,
            $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID)),
        );

        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_currency_comes_from_the_candidate_not_from_any_default(): void
    {
        $this->fakeAds->withManagerClients(P::MANAGER_ID, [
            new GoogleAdsManagedClient(P::MANAGER_ID, 0, true, 'Agency Manager', 'USD', 'America/New_York', 'ENABLED', false, false),
            new GoogleAdsManagedClient(P::CUSTOMER_ID, 1, false, 'Snap Booth Co', 'eur', 'Europe/Berlin', 'ENABLED', false, false),
        ]);

        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);

        $this->assertSame('EUR', $account->currency_code);
        $this->assertSame('Europe/Berlin', $account->time_zone);
        $this->assertFalse($account->is_test_account);
    }

    // ---------------------------------------------------------------
    // Tenancy
    // ---------------------------------------------------------------

    public function test_business_a_cannot_bind_business_bs_connection(): void
    {
        [, $b] = $this->adsTenant('Business B');
        $connectionB = $this->activeAdsConnection($b);

        // 1. The selector only ever uses the caller Business's own connection.
        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        $this->assertSame((int) $this->connection->id, (int) $account->business_google_connection_id);
        $this->assertNotSame((int) $connectionB->id, (int) $account->business_google_connection_id);

        // 2. A Business with NO connection never falls through to someone else's.
        [$customerC, $c] = $this->adsTenant('Business C');
        $this->assertSame(
            GoogleAdsAccountSelectionException::NOT_CONNECTED,
            $this->selectionReason(fn () => $this->selector()->select($c, (int) $customerC->user_id, P::CUSTOMER_ID)),
        );

        // 3. The schema itself refuses the cross-binding (composite FK).
        $this->expectException(QueryException::class);
        GoogleAdsAccount::create([
            'business_id' => $c->id,
            'business_google_connection_id' => $connectionB->id,
            'customer_id' => '9999999999',
            'currency_code' => 'USD',
            'time_zone' => 'UTC',
            'selected_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Re-selection and purge
    // ---------------------------------------------------------------

    /** @return array<string, int> */
    private function seedFacts(GoogleAdsAccount $account): array
    {
        $scope = ['business_id' => $account->business_id, 'google_ads_account_id' => $account->id];

        $campaign = GoogleAdsCampaign::create($scope + ['external_campaign_id' => P::CAMPAIGN_RENTAL, 'name' => 'Photo Booth Rental', 'status' => 'ENABLED']);
        $adGroup = GoogleAdsAdGroup::create($scope + ['google_ads_campaign_id' => $campaign->id, 'external_ad_group_id' => '3000000001', 'name' => 'General', 'status' => 'ENABLED']);
        GoogleAdsKeyword::create($scope + [
            'google_ads_campaign_id' => $campaign->id, 'google_ads_ad_group_id' => $adGroup->id,
            'external_criterion_id' => '3000000001~1', 'text' => 'photo booth', 'match_type' => 'PHRASE',
            'status' => 'ENABLED', 'is_negative' => false, 'level' => 'ad_group',
        ]);
        GoogleAdsSearchTerm::create($scope + [
            'google_ads_campaign_id' => $campaign->id, 'google_ads_ad_group_id' => $adGroup->id,
            'search_term' => 'photo booth', 'term_hash' => GoogleAdsSearchTerm::hashTerm('photo booth'), 'metric_date' => '2026-10-01',
        ]);
        GoogleAdsDailyMetric::create($scope + ['level' => 'campaign', 'entity_key' => P::CAMPAIGN_RENTAL, 'metric_date' => '2026-10-01', 'cost_micros' => 5_000_000]);
        GoogleAdsSyncRun::create($scope + ['state' => 'succeeded', 'trigger' => 'manual']);

        $account->forceFill([
            'monthly_budget_target_micros' => 250_000_000,
            'target_cpl_micros' => 25_000_000,
            'last_successful_sync_at' => now(),
            'data_through_date' => '2026-10-03',
            'last_sync_failure_code' => 'rate_limited',
        ])->save();

        return $scope;
    }

    private function factCount(GoogleAdsAccount $account): int
    {
        return collect(['google_ads_campaigns', 'google_ads_ad_groups', 'google_ads_keywords', 'google_ads_search_terms', 'google_ads_daily_metrics'])
            ->sum(fn (string $table) => DB::table($table)->where('google_ads_account_id', $account->id)->count());
    }

    public function test_reselecting_the_same_customer_keeps_facts_and_targets(): void
    {
        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        $this->seedFacts($account);

        $again = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);

        $this->assertSame((int) $account->id, (int) $again->id, 'one row per Business');
        $this->assertSame(1, GoogleAdsAccount::count());
        $this->assertSame(5, $this->factCount($again));
        $this->assertSame(250_000_000, (int) $again->monthly_budget_target_micros);
        $this->assertNotNull($again->last_successful_sync_at);
    }

    public function test_selection_is_refused_while_a_sync_is_live_and_changes_nothing(): void
    {
        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        $this->seedFacts($account);
        $run = GoogleAdsSyncRun::create(['business_id' => $this->business->id, 'google_ads_account_id' => $account->id, 'state' => 'queued', 'trigger' => 'connect']);

        $reason = $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, P::SECOND_MANAGED_CUSTOMER_ID));

        $this->assertSame(GoogleAdsAccountSelectionException::SYNC_RUNNING, $reason);
        $this->assertSame(P::CUSTOMER_ID, $account->fresh()->customer_id);
        $this->assertSame(5, $this->factCount($account));

        // A live claim counts too; once nothing is in flight the selection goes through.
        $run->forceFill(['state' => 'succeeded'])->save();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(2)]);
        $this->assertSame(
            GoogleAdsAccountSelectionException::SYNC_RUNNING,
            $this->selectionReason(fn () => $this->selector()->select($this->business, $this->actorId, P::SECOND_MANAGED_CUSTOMER_ID)),
        );

        DB::table('google_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => null]);
        $this->assertSame(P::SECOND_MANAGED_CUSTOMER_ID, $this->selector()->select($this->business, $this->actorId, P::SECOND_MANAGED_CUSTOMER_ID)->customer_id);
    }

    public function test_changing_the_customer_purges_facts_and_resets_targets_and_sync_state(): void
    {
        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        $this->seedFacts($account);
        $this->assertSame(5, $this->factCount($account));

        $changed = $this->selector()->select($this->business, $this->actorId, P::SECOND_MANAGED_CUSTOMER_ID);

        $this->assertSame((int) $account->id, (int) $changed->id);
        $this->assertSame(P::SECOND_MANAGED_CUSTOMER_ID, $changed->customer_id);
        $this->assertSame('CAD', $changed->currency_code);
        $this->assertSame(0, $this->factCount($changed), 'two customers must never mix');
        $this->assertNull($changed->monthly_budget_target_micros, 'a target in the old currency must not carry over');
        $this->assertNull($changed->target_cpl_micros);
        $this->assertNull($changed->last_successful_sync_at);
        $this->assertNull($changed->data_through_date);
        $this->assertNull($changed->last_sync_failure_code);

        $this->assertSame(1, GoogleAdsSyncRun::where('google_ads_account_id', $account->id)->count(), 'sync history is retained');
    }

    public function test_a_currency_change_on_the_same_customer_also_purges(): void
    {
        $account = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        $this->seedFacts($account);

        $this->fakeAds->withManagerClients(P::MANAGER_ID, [
            new GoogleAdsManagedClient(P::MANAGER_ID, 0, true, 'Agency Manager', 'USD', 'America/New_York', 'ENABLED', false, false),
            new GoogleAdsManagedClient(P::CUSTOMER_ID, 1, false, 'Snap Booth Co', 'EUR', 'America/New_York', 'ENABLED', true, false),
        ]);

        $changed = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);

        $this->assertSame(P::CUSTOMER_ID, $changed->customer_id);
        $this->assertSame('EUR', $changed->currency_code);
        $this->assertSame(0, $this->factCount($changed));
        $this->assertNull($changed->monthly_budget_target_micros);
    }

    public function test_another_business_facts_are_never_purged(): void
    {
        [$customerB, $b] = $this->adsTenant('Business B');
        $this->activeAdsConnection($b);
        $accountB = $this->selector()->select($b, (int) $customerB->user_id, P::CUSTOMER_ID);
        $this->seedFacts($accountB);

        $accountA = $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        $this->selector()->select($this->business, $this->actorId, P::SECOND_MANAGED_CUSTOMER_ID);

        $this->assertSame(5, $this->factCount($accountB));
        $this->assertSame(0, $this->factCount($accountA));
    }

    public function test_a_rate_limit_while_listing_selects_nothing_and_is_deferred(): void
    {
        $this->fakeAds->failNext('listAccessibleCustomers', GoogleAdsProviderException::rateLimited());

        $this->expectException(GoogleAdsProviderException::class);

        try {
            $this->selector()->select($this->business, $this->actorId, P::CUSTOMER_ID);
        } finally {
            $this->assertSame(0, GoogleAdsAccount::count());
            $this->assertSame(GoogleOperationStatus::Deferred, $this->operation(GoogleOperationType::AdsAccountsListed)->status);
        }
    }
}
