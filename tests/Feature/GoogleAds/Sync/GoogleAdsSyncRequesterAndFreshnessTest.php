<?php

namespace Tests\Feature\GoogleAds\Sync;

use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Jobs\GoogleAds\SyncGoogleAdsAccount;
use App\Library\GoogleAds\Sync\GoogleAdsFreshness;
use App\Library\GoogleAds\Sync\GoogleAdsFreshnessState;
use App\Library\GoogleAds\Sync\GoogleAdsSyncFailureCode;
use App\Library\GoogleAds\Sync\GoogleAdsSyncRequestOutcome;
use App\Library\GoogleAds\Sync\GoogleAdsSyncRequester;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleAds\Sync\Concerns\PreparesAdsSync;
use Tests\Feature\GoogleAds\Sync\Support\StubbedSyncEligibility;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §5 / §14 / §16 — the controller-facing sync
 * requester (throttle, dedupe, reuse of fresh data) and the freshness reader.
 */
class GoogleAdsSyncRequesterAndFreshnessTest extends TestCase
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

    private function requester(): GoogleAdsSyncRequester
    {
        return app(GoogleAdsSyncRequester::class);
    }

    private function freshness(): GoogleAdsFreshness
    {
        return app(GoogleAdsFreshness::class);
    }

    // ------------------------------------------------------------------
    // Requester
    // ------------------------------------------------------------------

    public function test_a_manual_refresh_queues_a_run_and_makes_no_provider_call(): void
    {
        [, , $account] = $this->syncableAccount();

        $result = $this->requester()->requestManual($account, 42);

        $this->assertSame(GoogleAdsSyncRequestOutcome::Queued, $result->outcome);
        $this->assertTrue($result->wasQueued());
        $this->assertSame(GoogleAdsSyncRunState::Queued, $result->run->state);
        $this->assertSame('manual', $result->run->trigger->value);
        Bus::assertDispatched(SyncGoogleAdsAccount::class, fn (SyncGoogleAdsAccount $job) => $job->accountId === $account->id && $job->actorUserId === 42 && $job->syncRunId === $result->run->id);
        $this->assertNotNull($account->fresh()->manual_refresh_requested_at);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_a_second_click_while_a_sync_is_pending_is_already_running(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->requester()->requestManual($account, 42);

        $second = $this->requester()->requestManual($account, 42);

        $this->assertSame(GoogleAdsSyncRequestOutcome::AlreadyRunning, $second->outcome);
        $this->assertSame(1, GoogleAdsSyncRun::query()->count());
        Bus::assertDispatchedTimes(SyncGoogleAdsAccount::class, 1);
    }

    public function test_manual_refresh_is_throttled_per_business_and_reopens_after_the_window(): void
    {
        [, , $account] = $this->syncableAccount();
        $first = $this->requester()->requestManual($account, 42);
        $first->run->forceFill(['state' => GoogleAdsSyncRunState::Failed])->save();
        $this->advance(10);

        $second = $this->requester()->requestManual($account, 42);

        $this->assertSame(GoogleAdsSyncRequestOutcome::Throttled, $second->outcome);
        $this->assertTrue($second->nextAllowedAt->equalTo(Carbon::parse(self::FIXTURE_TODAY . ' 12:00:00')->addMinutes(60)));
        Bus::assertDispatchedTimes(SyncGoogleAdsAccount::class, 1);

        $this->advance(51);
        $third = $this->requester()->requestManual($account, 42);

        $this->assertSame(GoogleAdsSyncRequestOutcome::Queued, $third->outcome);
        Bus::assertDispatchedTimes(SyncGoogleAdsAccount::class, 2);
    }

    public function test_a_recent_successful_sync_is_reused_instead_of_refreshing(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subMinutes(10)]);

        $result = $this->requester()->requestManual($account, 42);

        $this->assertSame(GoogleAdsSyncRequestOutcome::FreshEnough, $result->outcome);
        $this->assertTrue($result->nextAllowedAt->equalTo(now()->addMinutes(50)));
        $this->assertSame(0, GoogleAdsSyncRun::query()->count());
        Bus::assertNothingDispatched();
        $this->assertNull($account->fresh()->manual_refresh_requested_at);
    }

    public function test_a_sync_older_than_the_window_is_refreshed(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subMinutes(61)]);

        $this->assertSame(GoogleAdsSyncRequestOutcome::Queued, $this->requester()->requestManual($account, 42)->outcome);
    }

    public function test_an_account_that_cannot_sync_is_not_syncable(): void
    {
        [$business, , $account] = $this->syncableAccount();
        StubbedSyncEligibility::$deniedBusinessIds = [(int) $business->id];

        $this->assertSame(GoogleAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestManual($account, 42)->outcome);
        $this->assertSame(GoogleAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestInitial($account, 42)->outcome);
        Bus::assertNothingDispatched();
        $this->assertSame(0, GoogleAdsSyncRun::query()->count());
    }

    public function test_the_initial_request_is_not_throttled_but_is_deduplicated(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['manual_refresh_requested_at' => now(), 'last_successful_sync_at' => now()]);

        $first = $this->requester()->requestInitial($account, 42);
        $second = $this->requester()->requestInitial($account, 42);

        $this->assertSame(GoogleAdsSyncRequestOutcome::Queued, $first->outcome);
        $this->assertSame('connect', $first->run->trigger->value);
        $this->assertSame(GoogleAdsSyncRequestOutcome::AlreadyRunning, $second->outcome);
        Bus::assertDispatchedTimes(SyncGoogleAdsAccount::class, 1);
    }

    public function test_a_live_claim_makes_a_refresh_already_running(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(1)]);

        $this->assertSame(GoogleAdsSyncRequestOutcome::AlreadyRunning, $this->requester()->requestManual($account, 42)->outcome);
        $this->assertSame(GoogleAdsSyncRequestOutcome::AlreadyRunning, $this->requester()->requestInitial($account, 42)->outcome);
    }

    // ------------------------------------------------------------------
    // Freshness
    // ------------------------------------------------------------------

    public function test_freshness_states(): void
    {
        [, , $account] = $this->syncableAccount();

        $never = $this->freshness()->for($account->fresh());
        $this->assertSame(GoogleAdsFreshnessState::NeverSynced, $never->state);
        $this->assertNull($never->lastSuccessfulSyncAt);
        $this->assertFalse($never->isStale);
        $this->assertFalse($never->shouldWarn());

        DB::table('google_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(3), 'data_through_date' => '2026-10-03']);
        $fresh = $this->freshness()->for($account->fresh());
        $this->assertSame(GoogleAdsFreshnessState::Fresh, $fresh->state);
        $this->assertSame('2026-10-03', $fresh->dataThroughDate->toDateString());
        $this->assertFalse($fresh->isStale);
        $this->assertNull($fresh->lastFailureCode);

        DB::table('google_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(49)]);
        $stale = $this->freshness()->for($account->fresh());
        $this->assertSame(GoogleAdsFreshnessState::Stale, $stale->state);
        $this->assertTrue($stale->isStale);
        $this->assertTrue($stale->shouldWarn());

        DB::table('google_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(47)]);
        $this->assertFalse($this->freshness()->for($account->fresh())->isStale, 'stale only beyond twice the interval');

        DB::table('google_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(3), 'last_sync_failure_code' => 'timeout']);
        $failed = $this->freshness()->for($account->fresh());
        $this->assertSame(GoogleAdsFreshnessState::FailedWithData, $failed->state);
        $this->assertSame('timeout', $failed->lastFailureCode);
        $this->assertSame(GoogleAdsSyncFailureCode::label('timeout'), $failed->lastFailureLabel);

        $this->requester()->requestInitial($account->fresh(), 1);
        $this->assertSame(GoogleAdsFreshnessState::Running, $this->freshness()->for($account->fresh())->state);
    }

    public function test_a_stored_failure_code_that_is_not_in_the_safe_set_is_never_echoed(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update([
            'last_successful_sync_at' => now()->subHours(3),
            'last_sync_failure_code' => 'SQLSTATE secret',
        ]);

        $snapshot = $this->freshness()->for($account->fresh());

        $this->assertSame('unknown', $snapshot->lastFailureCode);
        $this->assertStringNotContainsString('SQLSTATE', (string) $snapshot->lastFailureLabel);
    }

    public function test_failure_code_labels_cover_every_stored_code(): void
    {
        foreach (['row_cap', 'currency_changed', 'not_syncable', 'already_running', 'expired', 'abandoned', 'internal_error', 'rate_limited', 'invalid_grant', 'access_denied', 'provider_unavailable', 'timeout', 'unexpected_response', 'not_found', 'validation', 'budget_exhausted'] as $code) {
            $this->assertTrue(GoogleAdsSyncFailureCode::isKnown($code), $code);
            $this->assertNotEmpty(GoogleAdsSyncFailureCode::label($code), $code);
        }

        $this->assertNull(GoogleAdsSyncFailureCode::label(null));
    }
}
