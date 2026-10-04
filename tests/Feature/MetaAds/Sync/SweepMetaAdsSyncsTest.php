<?php

namespace Tests\Feature\MetaAds\Sync;

use App\Enums\Business\BusinessStatus;
use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Jobs\MetaAds\SweepMetaAdsSyncs;
use App\Jobs\MetaAds\SyncMetaAdsAccount;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\MetaAds\Sync\Concerns\PreparesMetaSync;
use Tests\Feature\MetaAds\Sync\Support\StubbedMetaSyncEligibility;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §6 — the daily, staggered, deduplicated sweep
 * behind the project circuit breaker. Bus is faked: the sweep only queues, so
 * no provider is ever involved.
 */
class SweepMetaAdsSyncsTest extends TestCase
{
    use PreparesMetaSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSync();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sweep(): void
    {
        app()->call([new SweepMetaAdsSyncs(), 'handle']);
    }

    /** @return array<int, int> account ids that got a sync job */
    private function queuedAccountIds(): array
    {
        $ids = [];

        foreach (Bus::dispatched(SyncMetaAdsAccount::class) as $job) {
            $ids[] = $job->accountId;
        }

        sort($ids);

        return $ids;
    }

    private function operation(MetaOperationType $type, ?MetaProviderException $failure, int $businessId, ?Carbon $at = null): void
    {
        $ledger = app(MetaAdsOperationLedger::class);
        $operation = $ledger->open($businessId, $type, null, 'breaker fixture');
        $failure === null ? $ledger->succeed($operation) : $ledger->fail($operation, $failure);

        if ($at !== null) {
            DB::table('business_meta_operations')->where('id', $operation->id)->update(['created_at' => $at]);
        }
    }

    public function test_a_never_synced_or_stale_account_is_queued_with_a_queued_run_and_a_deterministic_stagger(): void
    {
        [, , $never] = $this->syncableAccount('Never');
        [, , $stale] = $this->syncableAccount('Stale');
        DB::table('meta_ads_accounts')->where('id', $stale->id)->update(['last_successful_sync_at' => now()->subHours(25)]);

        $this->sweep();

        $this->assertSame([$never->id, $stale->id], $this->queuedAccountIds());
        $this->assertSame(2, MetaAdsSyncRun::query()->where('state', MetaAdsSyncRunState::Queued->value)->where('trigger', 'scheduled')->count());

        Bus::assertDispatched(SyncMetaAdsAccount::class, function (SyncMetaAdsAccount $job) use ($stale): bool {
            return $job->accountId === $stale->id
                && $job->syncRunId !== null
                && Carbon::parse($job->delay)->equalTo(now()->addSeconds(SweepMetaAdsSyncs::staggerFor((int) $stale->id)));
        });
        $this->assertSame(($stale->id * 7) % 3600, SweepMetaAdsSyncs::staggerFor($stale->id));
        $this->assertLessThan(3600, SweepMetaAdsSyncs::staggerFor(999_999));
        $this->assertSame(0, $this->fakeMeta->callCount(), 'the sweep never calls Meta');
    }

    public function test_a_fresh_account_is_skipped_and_the_interval_is_floored_at_twenty_hours(): void
    {
        [, , $fresh] = $this->syncableAccount('Fresh');
        [, , $young] = $this->syncableAccount('Young');
        DB::table('meta_ads_accounts')->where('id', $fresh->id)->update(['last_successful_sync_at' => now()->subHours(2)]);
        DB::table('meta_ads_accounts')->where('id', $young->id)->update(['last_successful_sync_at' => now()->subHours(21)]);
        config(['meta_ads.sync.min_interval_hours' => 1]);

        $this->sweep();

        $this->assertSame([], $this->queuedAccountIds(), 'a configured interval under 20h falls back to 24h');
    }

    public function test_a_partial_run_is_picked_up_again_because_it_never_marked_the_account_complete(): void
    {
        [, , $account] = $this->syncableAccount('Partial');
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_sync_failure_code' => 'row_cap', 'last_successful_sync_at' => null]);

        $this->sweep();

        $this->assertSame([$account->id], $this->queuedAccountIds());
    }

    public function test_accounts_that_cannot_sync_are_skipped(): void
    {
        [, , $claimed] = $this->syncableAccount('Claimed');
        DB::table('meta_ads_accounts')->where('id', $claimed->id)->update(['sync_claimed_at' => now()->subMinutes(3)]);

        [, , $queued] = $this->syncableAccount('Queued');
        DB::table('meta_ads_sync_runs')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $queued->business_id, 'meta_ads_account_id' => $queued->id,
            'state' => 'queued', 'trigger' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        [, , $notSelected] = $this->syncableAccount('Not selected');
        DB::table('meta_ads_accounts')->where('id', $notSelected->id)->update(['selected_at' => null]);

        [$inactiveBusiness, , $inactive] = $this->syncableAccount('Inactive');
        DB::table('businesses')->where('id', $inactiveBusiness->id)->update(['status' => BusinessStatus::Inactive->value]);

        [$inactiveWorkspaceBusiness, , $inactiveWorkspace] = $this->syncableAccount('Inactive workspace');
        DB::table('workspaces')->where('id', $inactiveWorkspaceBusiness->workspace_id)->update(['is_active' => false]);

        [, $disconnectedConnection, $disconnected] = $this->syncableAccount('Disconnected');
        DB::table('business_meta_connections')->where('id', $disconnectedConnection->id)
            ->update(['state' => MetaConnectionState::Disconnected->value, 'access_token_encrypted' => null]);
        DB::table('meta_ads_accounts')->where('id', $disconnected->id)->update(['selected_at' => null]);

        [, $expiredConnection, $expired] = $this->syncableAccount('Expired');
        DB::table('business_meta_connections')->where('id', $expiredConnection->id)
            ->update(['state' => MetaConnectionState::Expired->value, 'access_token_encrypted' => null]);

        [, $revokedConnection, $revoked] = $this->syncableAccount('Revoked');
        DB::table('business_meta_connections')->where('id', $revokedConnection->id)
            ->update(['state' => MetaConnectionState::Revoked->value, 'access_token_encrypted' => null]);

        [, $otherUserConnection, $otherUser] = $this->syncableAccount('Other Meta user');
        DB::table('business_meta_connections')->where('id', $otherUserConnection->id)->update(['meta_user_id' => '5550001']);

        [$lostBusiness, , $lost] = $this->syncableAccount('Lost entitlement');
        StubbedMetaSyncEligibility::$deniedBusinessIds = [(int) $lostBusiness->id];

        [, , $ok] = $this->syncableAccount('Eligible');

        $this->sweep();

        $this->assertSame([$ok->id], $this->queuedAccountIds());
        $skipped = [$claimed->id, $notSelected->id, $inactive->id, $inactiveWorkspace->id, $disconnected->id, $expired->id, $revoked->id, $otherUser->id, $lost->id];
        $this->assertSame(0, MetaAdsSyncRun::query()->whereIn('meta_ads_account_id', $skipped)->count());
        $this->assertSame(1, MetaAdsSyncRun::query()->where('meta_ads_account_id', $queued->id)->count(), 'no duplicate run for an already-queued account');
    }

    public function test_an_active_connection_whose_token_ran_out_is_retired_by_the_sweep_not_synced(): void
    {
        [, $connection, $account] = $this->syncableAccount('Ran out');
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->subHour()]);

        $this->sweep();

        $this->assertSame([], $this->queuedAccountIds());
        $connection = $connection->fresh();
        $this->assertSame(MetaConnectionState::Expired, $connection->state, 'the owner now sees a reconnect state');
        $this->assertNull($connection->access_token_encrypted);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_a_second_sweep_does_not_queue_duplicates(): void
    {
        $this->syncableAccount('Once');

        $this->sweep();
        $this->sweep();

        $this->assertCount(1, $this->queuedAccountIds());
        $this->assertSame(1, MetaAdsSyncRun::query()->count());
    }

    public function test_a_lost_queued_run_is_retired_and_the_account_is_queued_again(): void
    {
        [, , $account] = $this->syncableAccount('Lost run');
        DB::table('meta_ads_sync_runs')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $account->business_id, 'meta_ads_account_id' => $account->id,
            'state' => 'queued', 'trigger' => 'scheduled', 'created_at' => now()->subHours(5), 'updated_at' => now()->subHours(5),
        ]);

        $this->sweep();

        $this->assertSame([$account->id], $this->queuedAccountIds());
        $this->assertSame(1, MetaAdsSyncRun::query()->where('failure_code', 'expired')->count());
    }

    public function test_it_sweeps_every_business_without_crossing_them(): void
    {
        [, , $a] = $this->syncableAccount('A');
        [, , $b] = $this->syncableAccount('B');
        [, , $c] = $this->syncableAccount('C');

        $this->sweep();

        $this->assertSame([$a->id, $b->id, $c->id], $this->queuedAccountIds());

        foreach (MetaAdsSyncRun::query()->get() as $run) {
            $this->assertSame((int) MetaAdsAccount::query()->find($run->meta_ads_account_id)->business_id, (int) $run->business_id);
        }
    }

    public function test_the_sweep_does_not_touch_google_ads_accounts_or_ledger(): void
    {
        $this->syncableAccount('Meta only');

        $this->sweep();

        $this->assertSame(0, DB::table('google_ads_sync_runs')->count());
        $this->assertSame(0, DB::table('business_google_operations')->count());
    }

    // ------------------------------------------------------------------
    // Circuit breaker
    // ------------------------------------------------------------------

    public function test_the_breaker_suppresses_the_whole_sweep_when_recent_syncs_are_all_back_pressure(): void
    {
        [$business] = $this->syncableAccount('Breaker');
        config(['meta_ads.sync.breaker_threshold' => 3]);
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::rateLimited(80004), $business->id);
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::providerUnavailable(2), $business->id);
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::budgetExhausted(), $business->id);

        $this->sweep();

        $this->assertSame([], $this->queuedAccountIds());
        $this->assertSame(0, MetaAdsSyncRun::query()->count());
    }

    public function test_the_breaker_stays_open_when_a_recent_sync_succeeded_or_there_are_too_few_or_they_are_old(): void
    {
        [$business, , $account] = $this->syncableAccount('Breaker');
        config(['meta_ads.sync.breaker_threshold' => 3, 'meta_ads.sync.breaker_cooldown_minutes' => 30]);

        // Fewer than N rows.
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::rateLimited(4), $business->id);
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::rateLimited(4), $business->id);
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());

        // A success among the last N.
        DB::table('meta_ads_sync_runs')->delete();
        Bus::fake();
        $this->operation(MetaOperationType::MetaAdsSync, null, $business->id);
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());

        // A plain failure among the last N.
        DB::table('meta_ads_sync_runs')->delete();
        DB::table('business_meta_operations')->delete();
        Bus::fake();
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::rateLimited(4), $business->id);
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::validation(100), $business->id);
        $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::rateLimited(4), $business->id);
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());

        // Outside the cool-down window.
        DB::table('meta_ads_sync_runs')->delete();
        DB::table('business_meta_operations')->delete();
        Bus::fake();
        foreach (range(1, 3) as $i) {
            $this->operation(MetaOperationType::MetaAdsSync, MetaProviderException::rateLimited(4), $business->id, now()->subMinutes(45));
        }
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());
    }

    public function test_operations_of_other_types_do_not_trip_the_meta_breaker(): void
    {
        [$business, , $account] = $this->syncableAccount('Breaker');
        config(['meta_ads.sync.breaker_threshold' => 3]);

        foreach (range(1, 3) as $i) {
            $this->operation(MetaOperationType::AccountsListed, MetaProviderException::rateLimited(4), $business->id);
        }

        $this->sweep();

        $this->assertSame([$account->id], $this->queuedAccountIds());
    }

    public function test_google_ledger_back_pressure_never_trips_the_meta_breaker(): void
    {
        [$business, , $account] = $this->syncableAccount('Breaker');
        config(['meta_ads.sync.breaker_threshold' => 3]);
        $ledger = app(\App\Library\GoogleAds\GoogleAdsOperationLedger::class);

        foreach (range(1, 3) as $i) {
            $operation = $ledger->open((int) $business->id, \App\Enums\GoogleBusinessProfile\GoogleOperationType::AdsSync, null, 'google back-pressure');
            $ledger->fail($operation, \App\Exceptions\GoogleAds\GoogleAdsProviderException::rateLimited());
        }

        $this->sweep();

        $this->assertSame([$account->id], $this->queuedAccountIds());
    }

    public function test_the_sweep_is_scheduled_daily_at_a_fixed_off_peak_time(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('SweepMetaAdsSyncs', $output);
        $this->assertMatchesRegularExpression('/10\s+3\s+\*\s+\*\s+\*\s+.*SweepMetaAdsSyncs/', $output);
        $this->assertMatchesRegularExpression('/40\s+2\s+\*\s+\*\s+\*\s+.*SweepGoogleAdsSyncs/', $output, 'Google sweep is untouched');
    }
}
