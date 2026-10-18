<?php

namespace Tests\Feature\MetaAds\Sync;

use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Jobs\MetaAds\SyncMetaAdsAccount;
use App\Library\MetaAds\Sync\MetaAdsFreshness;
use App\Library\MetaAds\Sync\MetaAdsFreshnessState;
use App\Library\MetaAds\Sync\MetaAdsSyncFailureCode;
use App\Library\MetaAds\Sync\MetaAdsSyncRequestOutcome;
use App\Library\MetaAds\Sync\MetaAdsSyncRequester;
use App\Models\MetaAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Sync\Concerns\PreparesMetaSync;
use Tests\Feature\MetaAds\Sync\Support\StubbedMetaSyncEligibility;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §6 — the controller-facing sync requester
 * (throttle, dedupe, reuse of fresh data) and the freshness reader.
 */
class MetaAdsSyncRequesterAndFreshnessTest extends TestCase
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

    private function requester(): MetaAdsSyncRequester
    {
        return app(MetaAdsSyncRequester::class);
    }

    private function freshness(): MetaAdsFreshness
    {
        return app(MetaAdsFreshness::class);
    }

    // ------------------------------------------------------------------
    // Requester
    // ------------------------------------------------------------------

    public function test_a_manual_refresh_queues_a_run_and_makes_no_provider_call(): void
    {
        [, , $account] = $this->syncableAccount();

        $result = $this->requester()->requestManual($account, 42);

        $this->assertSame(MetaAdsSyncRequestOutcome::Queued, $result->outcome);
        $this->assertTrue($result->wasQueued());
        $this->assertSame(MetaAdsSyncRunState::Queued, $result->run->state);
        $this->assertSame('manual', $result->run->trigger->value);
        Bus::assertDispatched(SyncMetaAdsAccount::class, fn (SyncMetaAdsAccount $job) => $job->accountId === $account->id && $job->actorUserId === 42 && $job->syncRunId === $result->run->id);
        $this->assertNotNull($account->fresh()->manual_refresh_requested_at);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_a_second_click_while_a_sync_is_pending_is_already_running(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->requester()->requestManual($account, 42);

        $second = $this->requester()->requestManual($account, 42);

        $this->assertSame(MetaAdsSyncRequestOutcome::AlreadyRunning, $second->outcome);
        $this->assertSame(1, MetaAdsSyncRun::query()->count());
        Bus::assertDispatchedTimes(SyncMetaAdsAccount::class, 1);
    }

    public function test_manual_refresh_is_throttled_per_business_and_reopens_after_the_window(): void
    {
        [, , $account] = $this->syncableAccount();
        $first = $this->requester()->requestManual($account, 42);
        $first->run->forceFill(['state' => MetaAdsSyncRunState::Failed])->save();
        $this->advance(10);

        $second = $this->requester()->requestManual($account, 42);

        $this->assertSame(MetaAdsSyncRequestOutcome::Throttled, $second->outcome);
        $this->assertTrue($second->nextAllowedAt->equalTo(Carbon::parse(self::FIXTURE_TODAY . ' 12:00:00')->addMinutes(60)));
        Bus::assertDispatchedTimes(SyncMetaAdsAccount::class, 1);

        $this->advance(51);
        $third = $this->requester()->requestManual($account, 42);

        $this->assertSame(MetaAdsSyncRequestOutcome::Queued, $third->outcome);
        Bus::assertDispatchedTimes(SyncMetaAdsAccount::class, 2);
    }

    public function test_the_throttle_window_comes_from_config(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.sync.manual_refresh_min_minutes' => 5]);
        $first = $this->requester()->requestManual($account, 42);
        $first->run->forceFill(['state' => MetaAdsSyncRunState::Failed])->save();

        $this->advance(3);
        $this->assertSame(MetaAdsSyncRequestOutcome::Throttled, $this->requester()->requestManual($account, 42)->outcome);

        $this->advance(3);
        $this->assertSame(MetaAdsSyncRequestOutcome::Queued, $this->requester()->requestManual($account, 42)->outcome);
    }

    public function test_a_recent_successful_sync_is_reused_instead_of_refreshing(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subMinutes(10)]);

        $result = $this->requester()->requestManual($account, 42);

        $this->assertSame(MetaAdsSyncRequestOutcome::FreshEnough, $result->outcome);
        $this->assertTrue($result->nextAllowedAt->equalTo(now()->addMinutes(50)));
        $this->assertSame(0, MetaAdsSyncRun::query()->count());
        Bus::assertNothingDispatched();
        $this->assertNull($account->fresh()->manual_refresh_requested_at);
    }

    public function test_a_sync_older_than_the_window_is_refreshed(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subMinutes(61)]);

        $this->assertSame(MetaAdsSyncRequestOutcome::Queued, $this->requester()->requestManual($account, 42)->outcome);
    }

    public function test_an_account_that_cannot_sync_is_not_syncable(): void
    {
        [$business, , $account] = $this->syncableAccount();
        StubbedMetaSyncEligibility::$deniedBusinessIds = [(int) $business->id];

        $this->assertSame(MetaAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestManual($account, 42)->outcome);
        $this->assertSame(MetaAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestInitial($account, 42)->outcome);
        Bus::assertNothingDispatched();
        $this->assertSame(0, MetaAdsSyncRun::query()->count());
    }

    public function test_an_unselected_expired_or_mismatched_account_is_not_syncable(): void
    {
        [, $connection, $account] = $this->syncableAccount();

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => null]);
        $this->assertSame(MetaAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestInitial($account, 1)->outcome);
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => now()]);

        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->subMinute()]);
        $this->assertSame(MetaAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestInitial($account, 1)->outcome);
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->addDays(30)]);

        DB::table('business_meta_connections')->where('id', $connection->id)->update(['meta_user_id' => '999']);
        $this->assertSame(MetaAdsSyncRequestOutcome::NotSyncable, $this->requester()->requestManual($account, 1)->outcome);

        Bus::assertNothingDispatched();
        $this->assertSame(0, MetaAdsSyncRun::query()->count());
    }

    public function test_the_initial_request_is_not_throttled_but_is_deduplicated(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['manual_refresh_requested_at' => now(), 'last_successful_sync_at' => now()]);

        $first = $this->requester()->requestInitial($account, 42);
        $second = $this->requester()->requestInitial($account, 42);

        $this->assertSame(MetaAdsSyncRequestOutcome::Queued, $first->outcome);
        $this->assertSame('connect', $first->run->trigger->value);
        $this->assertSame(MetaAdsSyncRequestOutcome::AlreadyRunning, $second->outcome);
        Bus::assertDispatchedTimes(SyncMetaAdsAccount::class, 1);
    }

    public function test_a_live_claim_makes_a_refresh_already_running(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(1)]);

        $this->assertSame(MetaAdsSyncRequestOutcome::AlreadyRunning, $this->requester()->requestManual($account, 42)->outcome);
        $this->assertSame(MetaAdsSyncRequestOutcome::AlreadyRunning, $this->requester()->requestInitial($account, 42)->outcome);
    }

    public function test_a_requester_for_one_business_never_touches_another(): void
    {
        [, , $a] = $this->syncableAccount('A');
        [, , $b] = $this->syncableAccount('B');

        $this->requester()->requestManual($a, 42);

        $this->assertNull($b->fresh()->manual_refresh_requested_at);
        $this->assertSame(0, MetaAdsSyncRun::query()->where('meta_ads_account_id', $b->id)->count());
    }

    // ------------------------------------------------------------------
    // Freshness
    // ------------------------------------------------------------------

    public function test_freshness_states(): void
    {
        [, , $account] = $this->syncableAccount();

        $never = $this->freshness()->for($account->fresh());
        $this->assertSame(MetaAdsFreshnessState::NeverSynced, $never->state);
        $this->assertNull($never->lastSuccessfulSyncAt);
        $this->assertFalse($never->isStale);
        $this->assertFalse($never->shouldWarn());

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(3), 'data_through_date' => '2026-10-03']);
        $fresh = $this->freshness()->for($account->fresh());
        $this->assertSame(MetaAdsFreshnessState::Fresh, $fresh->state);
        $this->assertSame('2026-10-03', $fresh->dataThroughDate->toDateString());
        $this->assertFalse($fresh->isStale);
        $this->assertNull($fresh->lastFailureCode);

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(49)]);
        $stale = $this->freshness()->for($account->fresh());
        $this->assertSame(MetaAdsFreshnessState::Stale, $stale->state);
        $this->assertTrue($stale->isStale);
        $this->assertTrue($stale->shouldWarn());

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(47)]);
        $this->assertFalse($this->freshness()->for($account->fresh())->isStale, 'stale only beyond twice the interval');

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(3), 'last_sync_failure_code' => 'timeout']);
        $failed = $this->freshness()->for($account->fresh());
        $this->assertSame(MetaAdsFreshnessState::FailedWithData, $failed->state);
        $this->assertSame('timeout', $failed->lastFailureCode);
        $this->assertSame(MetaAdsSyncFailureCode::label('timeout'), $failed->lastFailureLabel);

        $this->requester()->requestInitial($account->fresh(), 1);
        $this->assertSame(MetaAdsFreshnessState::Running, $this->freshness()->for($account->fresh())->state);
    }

    public function test_a_partial_codes_warn_with_data_and_never_look_fresh(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['last_successful_sync_at' => now()->subHours(3), 'last_sync_failure_code' => 'usage_high']);

        $snapshot = $this->freshness()->for($account->fresh());

        $this->assertSame(MetaAdsFreshnessState::FailedWithData, $snapshot->state);
        $this->assertTrue($snapshot->shouldWarn());
        $this->assertNotEmpty($snapshot->lastFailureLabel);
    }

    public function test_a_stored_failure_code_that_is_not_in_the_safe_set_is_never_echoed(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update([
            'last_successful_sync_at' => now()->subHours(3),
            'last_sync_failure_code' => 'SQLSTATE secret',
        ]);

        $snapshot = $this->freshness()->for($account->fresh());

        $this->assertSame('unknown', $snapshot->lastFailureCode);
        $this->assertStringNotContainsString('SQLSTATE', (string) $snapshot->lastFailureLabel);
    }

    public function test_failure_code_labels_cover_every_stored_code(): void
    {
        foreach ([
            'row_cap', 'usage_high', 'not_syncable', 'already_running', 'expired', 'abandoned', 'account_changed', 'connection_mismatch',
            'claim_lost', 'internal_error', 'rate_limited', 'token_expired', 'invalid_token', 'access_denied', 'provider_unavailable',
            'timeout', 'unexpected_response', 'not_found', 'validation', 'budget_exhausted',
        ] as $code) {
            $this->assertTrue(MetaAdsSyncFailureCode::isKnown($code), $code);
            $this->assertNotEmpty(MetaAdsSyncFailureCode::label($code), $code);
            $this->assertLessThanOrEqual(32, strlen($code), 'fits the varchar(32) columns');
        }

        $this->assertNull(MetaAdsSyncFailureCode::label(null));
    }

    public function test_every_provider_classification_maps_to_a_known_code(): void
    {
        foreach (\App\Exceptions\MetaAds\MetaProviderException::CLASSIFICATIONS as $classification) {
            $this->assertTrue(MetaAdsSyncFailureCode::isKnown($classification), $classification);
        }

        $this->assertSame('rate_limited', MetaAdsSyncFailureCode::forProviderException(\App\Exceptions\MetaAds\MetaProviderException::budgetExhausted()));
        $this->assertSame('rate_limited', MetaAdsSyncFailureCode::forProviderException(\App\Exceptions\MetaAds\MetaProviderException::rateLimited(4)));
        $this->assertSame('token_expired', MetaAdsSyncFailureCode::forProviderException(\App\Exceptions\MetaAds\MetaProviderException::invalidToken(463)));
        $this->assertSame('invalid_token', MetaAdsSyncFailureCode::forProviderException(\App\Exceptions\MetaAds\MetaProviderException::invalidToken(458)));
        $this->assertSame('timeout', MetaAdsSyncFailureCode::forProviderException(\App\Exceptions\MetaAds\MetaProviderException::timeout()));
    }
}
