<?php

namespace Tests\Feature\GoogleAds\Sync;

use App\Enums\Business\BusinessStatus;
use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Jobs\GoogleAds\SweepGoogleAdsSyncs;
use App\Jobs\GoogleAds\SyncGoogleAdsAccount;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleAds\Sync\Concerns\PreparesAdsSync;
use Tests\Feature\GoogleAds\Sync\Support\StubbedSyncEligibility;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §5 / §14 — the daily, staggered, deduplicated
 * sweep behind the project circuit breaker. Bus is faked: the sweep only
 * queues, so no provider is ever involved.
 */
class SweepGoogleAdsSyncsTest extends TestCase
{
    use PreparesAdsSync;
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
        app()->call([new SweepGoogleAdsSyncs(), 'handle']);
    }

    /** @return array<int, int> account ids that got a sync job */
    private function queuedAccountIds(): array
    {
        $ids = [];

        foreach (Bus::dispatched(SyncGoogleAdsAccount::class) as $job) {
            $ids[] = $job->accountId;
        }

        sort($ids);

        return $ids;
    }

    private function operation(GoogleOperationType $type, ?GoogleAdsProviderException $failure, int $businessId, ?Carbon $at = null): void
    {
        $ledger = app(GoogleAdsOperationLedger::class);
        $operation = $ledger->open($businessId, $type, null, 'breaker fixture');
        $failure === null ? $ledger->succeed($operation) : $ledger->fail($operation, $failure);

        if ($at !== null) {
            DB::table('business_google_operations')->where('id', $operation->id)->update(['created_at' => $at]);
        }
    }

    public function test_a_never_synced_or_stale_account_is_queued_with_a_queued_run_and_a_deterministic_stagger(): void
    {
        [, , $never] = $this->syncableAccount('Never');
        [, , $stale] = $this->syncableAccount('Stale');
        DB::table('google_ads_accounts')->where('id', $stale->id)->update(['last_successful_sync_at' => now()->subHours(25)]);

        $this->sweep();

        $this->assertSame([$never->id, $stale->id], $this->queuedAccountIds());
        $this->assertSame(2, GoogleAdsSyncRun::query()->where('state', GoogleAdsSyncRunState::Queued->value)->where('trigger', 'scheduled')->count());

        Bus::assertDispatched(SyncGoogleAdsAccount::class, function (SyncGoogleAdsAccount $job) use ($stale): bool {
            return $job->accountId === $stale->id
                && $job->syncRunId !== null
                && Carbon::parse($job->delay)->equalTo(now()->addSeconds(SweepGoogleAdsSyncs::staggerFor((int) $stale->id)));
        });
        $this->assertSame(($stale->id * 7) % 3600, SweepGoogleAdsSyncs::staggerFor($stale->id));
        $this->assertLessThan(3600, SweepGoogleAdsSyncs::staggerFor(999_999));
    }

    public function test_a_fresh_account_is_skipped_and_the_interval_is_floored_at_twenty_hours(): void
    {
        [, , $fresh] = $this->syncableAccount('Fresh');
        [, , $young] = $this->syncableAccount('Young');
        DB::table('google_ads_accounts')->where('id', $fresh->id)->update(['last_successful_sync_at' => now()->subHours(2)]);
        DB::table('google_ads_accounts')->where('id', $young->id)->update(['last_successful_sync_at' => now()->subHours(21)]);
        config(['google_ads.sync.min_interval_hours' => 1]);

        $this->sweep();

        $this->assertSame([], $this->queuedAccountIds(), 'a configured interval under 20h falls back to 24h');
    }

    public function test_accounts_that_cannot_sync_are_skipped(): void
    {
        [, , $claimed] = $this->syncableAccount('Claimed');
        DB::table('google_ads_accounts')->where('id', $claimed->id)->update(['sync_claimed_at' => now()->subMinutes(3)]);

        [, , $queued] = $this->syncableAccount('Queued');
        DB::table('google_ads_sync_runs')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $queued->business_id, 'google_ads_account_id' => $queued->id,
            'state' => 'queued', 'trigger' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$inactiveBusiness, , $inactive] = $this->syncableAccount('Inactive');
        DB::table('businesses')->where('id', $inactiveBusiness->id)->update(['status' => BusinessStatus::Inactive->value]);

        [, $disconnectedConnection, $disconnected] = $this->syncableAccount('Disconnected');
        DB::table('business_google_connections')->where('id', $disconnectedConnection->id)
            ->update(['state' => GoogleConnectionState::Disconnected->value, 'refresh_token_encrypted' => null]);

        [, $revokedConnection, $revoked] = $this->syncableAccount('Revoked');
        DB::table('business_google_connections')->where('id', $revokedConnection->id)
            ->update(['state' => GoogleConnectionState::Revoked->value, 'refresh_token_encrypted' => null]);

        [$lostBusiness, , $lost] = $this->syncableAccount('Lost entitlement');
        StubbedSyncEligibility::$deniedBusinessIds = [(int) $lostBusiness->id];

        [, , $ok] = $this->syncableAccount('Eligible');

        $this->sweep();

        $this->assertSame([$ok->id], $this->queuedAccountIds());
        $this->assertSame(0, GoogleAdsSyncRun::query()->whereIn('google_ads_account_id', [$claimed->id, $inactive->id, $disconnected->id, $revoked->id, $lost->id])->count());
        $this->assertSame(1, GoogleAdsSyncRun::query()->where('google_ads_account_id', $queued->id)->count(), 'no duplicate run for an already-queued account');
    }

    public function test_a_second_sweep_does_not_queue_duplicates(): void
    {
        $this->syncableAccount('Once');

        $this->sweep();
        $this->sweep();

        $this->assertCount(1, $this->queuedAccountIds());
        $this->assertSame(1, GoogleAdsSyncRun::query()->count());
    }

    public function test_a_lost_queued_run_is_retired_and_the_account_is_queued_again(): void
    {
        [, , $account] = $this->syncableAccount('Lost run');
        DB::table('google_ads_sync_runs')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $account->business_id, 'google_ads_account_id' => $account->id,
            'state' => 'queued', 'trigger' => 'scheduled', 'created_at' => now()->subHours(5), 'updated_at' => now()->subHours(5),
        ]);

        $this->sweep();

        $this->assertSame([$account->id], $this->queuedAccountIds());
        $this->assertSame(1, GoogleAdsSyncRun::query()->where('failure_code', 'expired')->count());
    }

    public function test_it_sweeps_every_business_without_crossing_them(): void
    {
        [, , $a] = $this->syncableAccount('A');
        [, , $b] = $this->syncableAccount('B');
        [, , $c] = $this->syncableAccount('C');

        $this->sweep();

        $this->assertSame([$a->id, $b->id, $c->id], $this->queuedAccountIds());

        foreach (GoogleAdsSyncRun::query()->get() as $run) {
            $this->assertSame((int) GoogleAdsAccount::query()->find($run->google_ads_account_id)->business_id, (int) $run->business_id);
        }
    }

    // ------------------------------------------------------------------
    // Circuit breaker
    // ------------------------------------------------------------------

    public function test_the_breaker_suppresses_the_whole_sweep_when_recent_syncs_are_all_back_pressure(): void
    {
        [$business, , $account] = $this->syncableAccount('Breaker');
        config(['google_ads.sync.breaker_threshold' => 3]);
        $this->operation(GoogleOperationType::AdsSync, GoogleAdsProviderException::rateLimited(), $business->id);
        $this->operation(GoogleOperationType::AdsSync, GoogleAdsProviderException::providerUnavailable(), $business->id);
        $this->operation(GoogleOperationType::AdsSync, GoogleAdsProviderException::rateLimited(), $business->id);

        $this->sweep();

        $this->assertSame([], $this->queuedAccountIds());
        $this->assertSame(0, GoogleAdsSyncRun::query()->count());
    }

    public function test_the_breaker_stays_open_when_a_recent_sync_succeeded_or_there_are_too_few_or_they_are_old(): void
    {
        [$business, , $account] = $this->syncableAccount('Breaker');
        config(['google_ads.sync.breaker_threshold' => 3, 'google_ads.sync.breaker_cooldown_minutes' => 30]);

        // Fewer than N rows.
        $this->operation(GoogleOperationType::AdsSync, GoogleAdsProviderException::rateLimited(), $business->id);
        $this->operation(GoogleOperationType::AdsSync, GoogleAdsProviderException::rateLimited(), $business->id);
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());

        // A success among the last N.
        DB::table('google_ads_sync_runs')->delete();
        Bus::fake();
        $this->operation(GoogleOperationType::AdsSync, null, $business->id);
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());

        // Outside the cool-down window.
        DB::table('google_ads_sync_runs')->delete();
        DB::table('business_google_operations')->delete();
        Bus::fake();
        foreach (range(1, 3) as $i) {
            $this->operation(GoogleOperationType::AdsSync, GoogleAdsProviderException::rateLimited(), $business->id, now()->subMinutes(45));
        }
        $this->sweep();
        $this->assertSame([$account->id], $this->queuedAccountIds());
    }

    public function test_operations_of_other_types_do_not_trip_the_ads_breaker(): void
    {
        [$business, , $account] = $this->syncableAccount('Breaker');
        config(['google_ads.sync.breaker_threshold' => 3]);

        foreach (range(1, 3) as $i) {
            $this->operation(GoogleOperationType::AdsAccountsListed, GoogleAdsProviderException::rateLimited(), $business->id);
        }

        $this->sweep();

        $this->assertSame([$account->id], $this->queuedAccountIds());
    }

    public function test_the_sweep_is_scheduled_daily_at_a_fixed_off_peak_time(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('SweepGoogleAdsSyncs', $output);
        $this->assertMatchesRegularExpression('/40\s+2\s+\*\s+\*\s+\*\s+.*SweepGoogleAdsSyncs/', $output);
    }
}
