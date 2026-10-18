<?php

namespace Tests\Feature\MetaAds;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsAccountSelectionException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaAdsAccountDirectory;
use App\Library\MetaAds\MetaAdsAccountSelector;
use App\Library\MetaAds\MetaPhotoBoothFixture as P;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\MetaAds\Concerns\CreatesMetaAdsFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §4 — candidates are derived SERVER-SIDE,
 * selection is explicit and fails CLOSED, and nothing the client posts but an
 * ad account id is ever trusted.
 */
class MetaAdsAccountSelectionTest extends TestCase
{
    use CreatesMetaAdsFixtures;
    use RefreshDatabase;

    private Business $business;

    private int $actorId;

    private BusinessMetaConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeMeta();

        [$customer, $this->business] = $this->metaTenant();
        $this->actorId = (int) $customer->user_id;
        $this->connection = $this->activeMetaConnection($this->business);
    }

    private function selector(): MetaAdsAccountSelector
    {
        return app(MetaAdsAccountSelector::class);
    }

    private function select(string $id): MetaAdsAccount
    {
        return $this->selector()->select($this->business, $this->connection, $id, $this->actorId);
    }

    private function operation(MetaOperationType $type): ?BusinessMetaOperation
    {
        return BusinessMetaOperation::query()
            ->where('business_id', $this->business->id)
            ->where('operation_type', $type->value)
            ->latest('id')
            ->first();
    }

    private function reason(callable $attempt): string
    {
        try {
            $attempt();
        } catch (MetaAdsAccountSelectionException $e) {
            return $e->reason;
        }

        $this->fail('expected the selection to be refused');
    }

    /** Seeds one row in every fact table for the account; returns the count. */
    private function seedFacts(MetaAdsAccount $account): int
    {
        $now = now();
        $base = ['business_id' => $account->business_id, 'meta_ads_account_id' => $account->id, 'created_at' => $now, 'updated_at' => $now];

        $campaign = DB::table('meta_ads_campaigns')->insertGetId($base + [
            'uid' => (string) Str::uuid(), 'external_campaign_id' => '111', 'name' => 'C', 'status' => 'ACTIVE',
        ]);
        $adSet = DB::table('meta_ads_ad_sets')->insertGetId($base + [
            'uid' => (string) Str::uuid(), 'meta_ads_campaign_id' => $campaign, 'external_ad_set_id' => '222', 'name' => 'S', 'status' => 'ACTIVE',
        ]);
        $ad = DB::table('meta_ads_ads')->insertGetId($base + [
            'uid' => (string) Str::uuid(), 'meta_ads_campaign_id' => $campaign, 'meta_ads_ad_set_id' => $adSet,
            'external_ad_id' => '333', 'name' => 'A', 'status' => 'ACTIVE',
        ]);
        DB::table('meta_ads_daily_insights')->insert($base + [
            'level' => 'campaign', 'entity_id' => $campaign, 'metric_date' => '2026-10-01', 'spend_micros' => 5_000_000, 'impressions' => 10, 'clicks' => 1,
        ]);
        DB::table('meta_ads_daily_results')->insert($base + [
            'level' => 'campaign', 'entity_id' => $campaign, 'metric_date' => '2026-10-01', 'action_type' => 'lead', 'results' => 2,
        ]);

        $account->forceFill([
            'monthly_budget_target_micros' => 250_000_000,
            'target_cost_per_result_micros' => 12_000_000,
            'result_action_type' => 'lead',
            'last_successful_sync_at' => now()->subHour(),
            'data_through_date' => '2026-10-03',
        ])->save();

        return $ad ? 5 : 0;
    }

    private function factCount(MetaAdsAccount $account): int
    {
        return collect(['meta_ads_campaigns', 'meta_ads_ad_sets', 'meta_ads_ads', 'meta_ads_daily_insights', 'meta_ads_daily_results'])
            ->sum(fn (string $table) => DB::table($table)->where('meta_ads_account_id', $account->id)->count());
    }

    // ---------------------------------------------------------------
    // Candidates
    // ---------------------------------------------------------------

    public function test_candidates_are_derived_from_the_provider_sorted_and_nothing_is_auto_selected(): void
    {
        $candidates = app(MetaAdsAccountDirectory::class)->candidates($this->business, $this->connection, $this->actorId);

        $this->assertSame([P::AD_ACCOUNT_ID, P::EUR_AD_ACCOUNT_ID, P::DISABLED_AD_ACCOUNT_ID], array_map(fn ($c) => $c->adAccountId, $candidates));
        $this->assertSame('USD', $candidates[0]->currencyCode);
        $this->assertTrue($candidates[0]->isSelectable());
        $this->assertFalse($candidates[2]->isSelectable());
        $this->assertSame(0, MetaAdsAccount::count());

        $ledger = $this->operation(MetaOperationType::AccountsListed);
        $this->assertSame(MetaOperationStatus::Succeeded, $ledger->status);
        $this->assertGreaterThanOrEqual(1, $ledger->provider_call_count);
    }

    public function test_never_auto_selects_even_with_exactly_one_candidate(): void
    {
        $this->fakeMeta->withAdAccounts([new MetaAdsAccountCandidate(P::AD_ACCOUNT_ID, 'Only One', 'USD', 'America/New_York', 1)]);

        $candidates = app(MetaAdsAccountDirectory::class)->candidates($this->business, $this->connection, $this->actorId);

        $this->assertCount(1, $candidates);
        $this->assertSame(0, MetaAdsAccount::count());
    }

    public function test_the_directory_refuses_another_business(): void
    {
        [, $other] = $this->metaTenant('Other');

        $this->expectException(LogicException::class);
        app(MetaAdsAccountDirectory::class)->candidates($other, $this->connection, $this->actorId);
    }

    public function test_listing_with_a_dead_token_makes_no_provider_call(): void
    {
        $this->connection->forceFill(['token_expires_at' => now()->subMinute()])->save();

        try {
            app(MetaAdsAccountDirectory::class)->candidates($this->business, $this->connection->fresh(), $this->actorId);
            $this->fail('expected token_expired');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::TOKEN_EXPIRED, $e->classification);
        }

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(MetaConnectionState::Expired, $this->connection->fresh()->state);
    }

    // ---------------------------------------------------------------
    // select()
    // ---------------------------------------------------------------

    public function test_explicit_selection_takes_currency_and_time_zone_from_the_fresh_candidate(): void
    {
        $account = $this->select(P::EUR_AD_ACCOUNT_ID);

        $this->assertSame(P::EUR_AD_ACCOUNT_ID, $account->ad_account_id);
        $this->assertSame('EUR', $account->currency_code);
        $this->assertSame('Europe/Berlin', $account->time_zone);
        $this->assertSame('Photo Booth Co - Europe', $account->name);
        $this->assertSame(1, (int) $account->account_status);
        $this->assertNotNull($account->selected_at);
        $this->assertSame($this->actorId, (int) $account->selected_by_user_id);
        $this->assertSame(P::META_USER_ID, $account->selected_meta_user_id);
        $this->assertSame((int) $this->connection->id, (int) $account->business_meta_connection_id);
        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation(MetaOperationType::AccountSelected)->status);
    }

    public function test_an_act_prefix_is_tolerated_and_malformed_ids_are_refused_before_any_provider_call(): void
    {
        $this->assertSame(P::AD_ACCOUNT_ID, $this->select('act_' . P::AD_ACCOUNT_ID)->ad_account_id);

        $before = $this->fakeMeta->callCount();

        foreach (['', 'abc', '12 34', 'act_', 'act_12a', '1234567890123456789012', "1;DROP", '-1', 'ACT-1'] as $bad) {
            $this->assertSame(MetaAdsAccountSelectionException::INVALID_ACCOUNT_ID, $this->reason(fn () => $this->select($bad)), $bad);
        }

        $this->assertSame($before, $this->fakeMeta->callCount());
    }

    public function test_a_foreign_account_id_fails_closed(): void
    {
        $this->assertSame(MetaAdsAccountSelectionException::NOT_A_CANDIDATE, $this->reason(fn () => $this->select(P::FOREIGN_AD_ACCOUNT_ID)));
        $this->assertSame(MetaAdsAccountSelectionException::NOT_A_CANDIDATE, $this->reason(fn () => $this->select('424242')));
        $this->assertSame(0, MetaAdsAccount::count());
    }

    public function test_another_businesses_account_id_is_never_in_this_businesss_candidate_set(): void
    {
        // Business B's Meta user reaches only account 555; Business A's reaches only the fixture's.
        [$customerB, $businessB] = $this->metaTenant('B');
        $connectionB = $this->activeMetaConnection($businessB);

        $this->fakeMeta->withAdAccounts([new MetaAdsAccountCandidate('555000555', 'B Only', 'USD', 'America/New_York', 1)]);
        $this->selector()->select($businessB, $connectionB, '555000555', (int) $customerB->user_id);

        $this->fakeMeta->usePhotoBoothFixture();
        $this->assertSame(MetaAdsAccountSelectionException::NOT_A_CANDIDATE, $this->reason(fn () => $this->select('555000555')));
        $this->assertSame(0, MetaAdsAccount::where('business_id', $this->business->id)->count());
        $this->assertSame('555000555', MetaAdsAccount::where('business_id', $businessB->id)->value('ad_account_id'), 'B untouched');
    }

    public function test_business_a_cannot_select_through_business_bs_connection(): void
    {
        [, $businessB] = $this->metaTenant('B');
        $connectionB = $this->activeMetaConnection($businessB);

        $reason = $this->reason(fn () => $this->selector()->select($this->business, $connectionB, P::AD_ACCOUNT_ID, $this->actorId));

        $this->assertSame(MetaAdsAccountSelectionException::NOT_CONNECTED, $reason);
        $this->assertSame(0, MetaAdsAccount::count());
        $this->assertSame(0, $this->fakeMeta->callCount('listAdAccounts'));
    }

    public function test_a_disabled_account_is_listed_but_not_selectable(): void
    {
        $this->assertSame(MetaAdsAccountSelectionException::NOT_SELECTABLE, $this->reason(fn () => $this->select(P::DISABLED_AD_ACCOUNT_ID)));
        $this->assertSame(0, MetaAdsAccount::count());
        $this->assertNull($this->operation(MetaOperationType::AccountSelected));
    }

    public function test_an_inactive_connection_cannot_select(): void
    {
        foreach ([MetaConnectionState::Expired, MetaConnectionState::Revoked, MetaConnectionState::Disconnected, MetaConnectionState::Pending] as $state) {
            $this->connection->forceFill(['state' => $state])->save();

            $this->assertSame(MetaAdsAccountSelectionException::NOT_CONNECTED, $this->reason(fn () => $this->select(P::AD_ACCOUNT_ID)), $state->value);
        }

        $this->assertSame(0, $this->fakeMeta->callCount('listAdAccounts'));
    }

    public function test_a_stale_candidate_is_refused_because_the_set_is_re_derived_on_every_select(): void
    {
        $this->select(P::AD_ACCOUNT_ID);

        // The account is removed at Meta between the render and the POST.
        $this->fakeMeta->withAdAccounts([new MetaAdsAccountCandidate(P::EUR_AD_ACCOUNT_ID, 'Photo Booth Co - Europe', 'EUR', 'Europe/Berlin', 1)]);

        $this->assertSame(MetaAdsAccountSelectionException::NOT_A_CANDIDATE, $this->reason(fn () => $this->select(P::AD_ACCOUNT_ID)));
        $this->assertSame(P::AD_ACCOUNT_ID, MetaAdsAccount::first()->ad_account_id, 'nothing changed');
    }

    // ---------------------------------------------------------------
    // Re-select / change
    // ---------------------------------------------------------------

    public function test_reselecting_the_same_account_keeps_facts_and_targets_and_restamps_selection(): void
    {
        $account = $this->select(P::AD_ACCOUNT_ID);
        $this->seedFacts($account);
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => now()->subDays(3), 'selected_by_user_id' => null, 'selected_meta_user_id' => null]);

        $again = $this->select(P::AD_ACCOUNT_ID);

        $this->assertSame((int) $account->id, (int) $again->id, 'one row per Business');
        $this->assertSame(1, MetaAdsAccount::count());
        $this->assertSame(5, $this->factCount($again));
        $this->assertSame(250_000_000, (int) $again->monthly_budget_target_micros);
        $this->assertSame(12_000_000, (int) $again->target_cost_per_result_micros);
        $this->assertSame('lead', $again->result_action_type);
        $this->assertNotNull($again->last_successful_sync_at);
        $this->assertTrue($again->selected_at->isToday());
        $this->assertSame($this->actorId, (int) $again->selected_by_user_id);
        $this->assertSame(P::META_USER_ID, $again->selected_meta_user_id);
    }

    public function test_reselecting_after_a_disconnect_restores_the_selection_without_losing_facts(): void
    {
        $account = $this->select(P::AD_ACCOUNT_ID);
        $this->seedFacts($account);
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => null]);

        $this->select(P::AD_ACCOUNT_ID);

        $this->assertNotNull($account->fresh()->selected_at);
        $this->assertSame(5, $this->factCount($account));
    }

    public function test_changing_the_account_purges_facts_and_resets_targets_result_type_and_sync_state(): void
    {
        $account = $this->select(P::AD_ACCOUNT_ID);
        $this->seedFacts($account);
        DB::table('meta_ads_sync_runs')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'meta_ads_account_id' => $account->id,
            'state' => 'succeeded', 'trigger' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(5, $this->factCount($account));

        $changed = $this->select(P::EUR_AD_ACCOUNT_ID);

        $this->assertSame((int) $account->id, (int) $changed->id);
        $this->assertSame(P::EUR_AD_ACCOUNT_ID, $changed->ad_account_id);
        $this->assertSame('EUR', $changed->currency_code);
        $this->assertSame(0, $this->factCount($changed), 'two currencies never mix');
        $this->assertNull($changed->monthly_budget_target_micros);
        $this->assertNull($changed->target_cost_per_result_micros);
        $this->assertNull($changed->result_action_type);
        $this->assertNull($changed->last_successful_sync_at);
        $this->assertNull($changed->data_through_date);
        $this->assertSame(1, DB::table('meta_ads_sync_runs')->where('meta_ads_account_id', $account->id)->count(), 'sync run history is retained');
    }

    public function test_another_businesses_facts_are_never_purged(): void
    {
        [$customerB, $businessB] = $this->metaTenant('B');
        $connectionB = $this->activeMetaConnection($businessB);
        $accountB = $this->selectedMetaAccount($businessB);
        $this->seedFacts($accountB);

        $account = $this->select(P::AD_ACCOUNT_ID);
        $this->seedFacts($account);
        $this->select(P::EUR_AD_ACCOUNT_ID);

        $this->assertSame(5, $this->factCount($accountB->fresh()));
        $this->assertSame(250_000_000, (int) $accountB->fresh()->monthly_budget_target_micros);
        $this->assertNotNull($connectionB->fresh());
    }

    // ---------------------------------------------------------------
    // Live sync
    // ---------------------------------------------------------------

    public function test_selection_is_refused_while_a_sync_is_live_and_changes_nothing(): void
    {
        $account = $this->select(P::AD_ACCOUNT_ID);
        $this->seedFacts($account);

        // A live claim.
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(2)]);
        $this->assertSame(MetaAdsAccountSelectionException::SYNC_RUNNING, $this->reason(fn () => $this->select(P::EUR_AD_ACCOUNT_ID)));
        $this->assertSame(P::AD_ACCOUNT_ID, $account->fresh()->ad_account_id);
        $this->assertSame(5, $this->factCount($account));

        // A stale claim (dead worker) does not block.
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(20)]);
        $this->assertSame(P::EUR_AD_ACCOUNT_ID, $this->select(P::EUR_AD_ACCOUNT_ID)->ad_account_id);

        // A running sync_runs row blocks too, and a finished one does not.
        $fresh = MetaAdsAccount::first();
        $runId = DB::table('meta_ads_sync_runs')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'meta_ads_account_id' => $fresh->id,
            'state' => 'running', 'trigger' => 'schedule', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(MetaAdsAccountSelectionException::SYNC_RUNNING, $this->reason(fn () => $this->select(P::AD_ACCOUNT_ID)));
        $this->assertSame(P::EUR_AD_ACCOUNT_ID, $fresh->fresh()->ad_account_id);

        DB::table('meta_ads_sync_runs')->where('id', $runId)->update(['state' => 'succeeded']);
        $this->assertSame(P::AD_ACCOUNT_ID, $this->select(P::AD_ACCOUNT_ID)->ad_account_id);
    }

    public function test_a_sync_refusal_makes_no_provider_call(): void
    {
        $account = $this->select(P::AD_ACCOUNT_ID);
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()]);
        $calls = $this->fakeMeta->callCount();

        $this->reason(fn () => $this->select(P::EUR_AD_ACCOUNT_ID));

        $this->assertSame($calls, $this->fakeMeta->callCount());
    }

    // ---------------------------------------------------------------
    // Failure paths
    // ---------------------------------------------------------------

    public function test_a_rate_limit_while_listing_selects_nothing_and_is_deferred(): void
    {
        $this->fakeMeta->failNext('listAdAccounts', MetaProviderException::rateLimited(17));

        try {
            $this->select(P::AD_ACCOUNT_ID);
            $this->fail('expected rate_limited');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::RATE_LIMITED, $e->classification);
        }

        $this->assertSame(0, MetaAdsAccount::count());
        $this->assertSame(MetaOperationStatus::Deferred, $this->operation(MetaOperationType::AccountsListed)->status);
        $this->assertNull($this->operation(MetaOperationType::AccountSelected));
    }
}
