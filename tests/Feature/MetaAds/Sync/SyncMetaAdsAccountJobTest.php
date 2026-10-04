<?php

namespace Tests\Feature\MetaAds\Sync;

use App\Enums\Business\BusinessStatus;
use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Enums\MetaAds\MetaConnectionState;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Jobs\Base;
use App\Jobs\MetaAds\SyncMetaAdsAccount;
use App\Library\MetaAds\Sync\MetaAdsSyncDispatcher;
use App\Library\MetaAds\Sync\MetaAdsSyncEligibility;
use App\Library\MetaAds\Sync\MetaAdsSyncGuard;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\MetaAds\Sync\Concerns\PreparesMetaSync;
use Tests\Feature\MetaAds\Sync\Support\StubbedMetaSyncEligibility;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §6 — the SyncMetaAdsAccount job: re-checks
 * before any provider call, one-at-a-time claim, claim always released.
 */
class SyncMetaAdsAccountJobTest extends TestCase
{
    use PreparesMetaSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Bus is faked in some tests, so run the handler directly, as a worker would. */
    private function runJob(SyncMetaAdsAccount $job): void
    {
        app()->call([$job, 'handle']);
    }

    private function latestRun(MetaAdsAccount $account): MetaAdsSyncRun
    {
        return MetaAdsSyncRun::query()->where('meta_ads_account_id', $account->id)->latest('id')->firstOrFail();
    }

    public function test_the_job_extends_base_and_never_retries(): void
    {
        $job = new SyncMetaAdsAccount(1);

        $this->assertInstanceOf(Base::class, $job);
        $this->assertSame(1, $job->tries);
    }

    public function test_the_job_runs_a_full_sync_and_releases_the_claim(): void
    {
        [, , $account] = $this->syncableAccount();

        SyncMetaAdsAccount::dispatchSync($account->id, MetaAdsSyncTrigger::Connect, (int) \App\Models\User::query()->value('id'));

        $run = $this->latestRun($account);
        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(MetaAdsSyncTrigger::Connect, $run->trigger);
        $this->assertGreaterThan(0, DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->count());
        $this->assertNull($account->fresh()->sync_claimed_at);
    }

    public function test_a_queued_run_is_the_one_the_job_executes(): void
    {
        [, , $account] = $this->syncableAccount();
        Bus::fake();
        $actor = (int) \App\Models\User::query()->value('id');
        $queued = app(MetaAdsSyncDispatcher::class)->queue($account, MetaAdsSyncTrigger::Manual, $actor);
        Bus::assertDispatched(SyncMetaAdsAccount::class);

        $this->runJob(new SyncMetaAdsAccount($account->id, MetaAdsSyncTrigger::Manual, $actor, $queued->id));

        $this->assertSame(1, MetaAdsSyncRun::query()->where('meta_ads_account_id', $account->id)->count());
        $this->assertSame(MetaAdsSyncRunState::Succeeded, $queued->fresh()->state);
    }

    public function test_a_run_that_is_no_longer_queued_is_not_executed_again(): void
    {
        [, , $account] = $this->syncableAccount();
        $run = app(MetaAdsSyncDispatcher::class)->createQueuedRun($account, MetaAdsSyncTrigger::Manual);
        $run->forceFill(['state' => MetaAdsSyncRunState::Failed])->save();

        SyncMetaAdsAccount::dispatchSync($account->id, MetaAdsSyncTrigger::Manual, null, $run->id);

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(MetaAdsSyncRunState::Failed, $run->fresh()->state);
    }

    public function test_a_run_of_another_account_is_never_executed_by_this_job(): void
    {
        [, , $a] = $this->syncableAccount('A');
        [, , $b] = $this->syncableAccount('B');
        $runOfB = app(MetaAdsSyncDispatcher::class)->createQueuedRun($b, MetaAdsSyncTrigger::Manual);

        SyncMetaAdsAccount::dispatchSync($a->id, MetaAdsSyncTrigger::Manual, null, $runOfB->id);

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(MetaAdsSyncRunState::Queued, $runOfB->fresh()->state);
        $this->assertSame(0, DB::table('meta_ads_campaigns')->count());
    }

    // ------------------------------------------------------------------
    // Claim
    // ------------------------------------------------------------------

    public function test_a_live_claim_makes_the_job_skip_without_any_provider_call(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(2)]);

        SyncMetaAdsAccount::dispatchSync($account->id);

        $run = $this->latestRun($account);
        $this->assertSame(MetaAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('already_running', $run->failure_code);
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertNotNull($account->fresh()->sync_claimed_at, 'another worker owns the claim; this job must not release it');
    }

    public function test_a_stale_claim_is_taken_over(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(16)]);

        SyncMetaAdsAccount::dispatchSync($account->id);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $this->latestRun($account)->state);
        $this->assertNull($account->fresh()->sync_claimed_at);
    }

    public function test_a_job_whose_claim_was_taken_over_stops_and_does_not_release_the_new_owners_claim(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->hook->before = function (string $method) use ($account): void {
            if ($method === 'campaigns') {
                // The claim went stale and another worker took it over mid-run.
                DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => '2026-10-04 12:14:00']);
            }
        };

        SyncMetaAdsAccount::dispatchSync($account->id);

        $run = $this->latestRun($account);
        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('claim_lost', $run->failure_code);
        $this->assertSame(0, $this->fakeMeta->callCount('adSets'), 'no further stage runs once the claim is lost');
        $this->assertSame('2026-10-04 12:14:00', (string) DB::table('meta_ads_accounts')->where('id', $account->id)->value('sync_claimed_at'), 'the old job must not release the new owner');
    }

    public function test_every_stage_heartbeats_the_claim_so_a_long_run_is_never_stale(): void
    {
        [, , $account] = $this->syncableAccount();
        $stolen = 'untouched';
        $this->hook->before = function (string $method) use ($account, &$stolen): void {
            if ($method === 'adSets') {
                $this->advance(10);
            }

            if ($method === 'ads') {
                $this->advance(10); // 20 minutes after the claim was taken, 10 after the last heartbeat
                $stolen = app(MetaAdsSyncGuard::class)->acquire((int) $account->id);
            }
        };

        SyncMetaAdsAccount::dispatchSync($account->id);

        $this->assertNull($stolen, 'a claim that heartbeats at each stage is not stealable');
        $this->assertSame(MetaAdsSyncRunState::Succeeded, $this->latestRun($account)->state);
        $this->assertNull($account->fresh()->sync_claimed_at, 'the refreshed claim is still ours, so it is released');
    }

    public function test_two_concurrent_syncs_for_one_account_run_the_provider_once(): void
    {
        [, , $account] = $this->syncableAccount();
        $fired = false;
        $this->hook->before = function (string $method) use (&$fired, $account): void {
            if ($method === 'campaigns' && ! $fired) {
                $fired = true;
                // A second worker starts while the first is mid-sync.
                SyncMetaAdsAccount::dispatchSync($account->id, MetaAdsSyncTrigger::Manual);
            }
        };

        SyncMetaAdsAccount::dispatchSync($account->id);

        $this->assertSame(1, $this->fakeMeta->callCount('campaigns'));
        $this->assertSame(1, $this->fakeMeta->callCount('accountDetails'));
        $states = MetaAdsSyncRun::query()->where('meta_ads_account_id', $account->id)->orderBy('id')->pluck('state')->map->value->all();
        $this->assertSame(['succeeded', 'skipped'], $states);
        $this->assertNull($account->fresh()->sync_claimed_at, 'the first job released the claim at the end');
    }

    public function test_the_claim_is_released_after_a_provider_failure_which_does_not_crash_the_job(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->failNext('campaigns', MetaProviderException::rateLimited(80004));

        SyncMetaAdsAccount::dispatchSync($account->id);

        $this->assertSame(MetaAdsSyncRunState::Skipped, $this->latestRun($account)->state);
        $this->assertNull($account->fresh()->sync_claimed_at);
    }

    public function test_the_claim_is_released_after_a_programming_error_which_is_not_swallowed(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->hook->before = function (string $method): void {
            if ($method === 'adSets') {
                throw new RuntimeException('boom');
            }
        };

        try {
            SyncMetaAdsAccount::dispatchSync($account->id);
            $this->fail('Programming errors must reach the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertNull($account->fresh()->sync_claimed_at);
        $this->assertSame('internal_error', $this->latestRun($account)->failure_code);
    }

    // ------------------------------------------------------------------
    // Re-checks before any provider call
    // ------------------------------------------------------------------

    private function assertRefusedWithoutProviderCall(MetaAdsAccount $account): void
    {
        SyncMetaAdsAccount::dispatchSync($account->id);

        $run = $this->latestRun($account);
        $this->assertSame(MetaAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('not_syncable', $run->failure_code);
        $this->assertSame(0, $this->fakeMeta->callCount(), 'no provider call of any kind');
        $this->assertNull($account->fresh()->sync_claimed_at);
        $this->assertSame(0, DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->count());
    }

    public function test_an_inactive_business_makes_no_provider_call(): void
    {
        [$business, , $account] = $this->syncableAccount();
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_an_inactive_workspace_makes_no_provider_call(): void
    {
        [$business, , $account] = $this->syncableAccount();
        DB::table('workspaces')->where('id', $business->workspace_id)->update(['is_active' => false]);

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_lost_entitlement_makes_no_provider_call(): void
    {
        [$business, , $account] = $this->syncableAccount();
        StubbedMetaSyncEligibility::$deniedBusinessIds = [(int) $business->id];

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_an_unselected_account_makes_no_provider_call(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => null]);

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_an_expired_revoked_or_disconnected_connection_makes_no_provider_call(): void
    {
        foreach ([MetaConnectionState::Expired, MetaConnectionState::Revoked, MetaConnectionState::Disconnected] as $state) {
            [, $connection, $account] = $this->syncableAccount('Business ' . $state->value);
            DB::table('business_meta_connections')->where('id', $connection->id)->update(['state' => $state->value, 'access_token_encrypted' => null]);

            $this->assertRefusedWithoutProviderCall($account);
        }
    }

    public function test_a_connection_revoked_after_queueing_makes_no_provider_call(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        Bus::fake();
        $queued = app(MetaAdsSyncDispatcher::class)->queue($account, MetaAdsSyncTrigger::Scheduled);
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['state' => MetaConnectionState::Revoked->value, 'access_token_encrypted' => null]);

        $this->runJob(new SyncMetaAdsAccount($account->id, MetaAdsSyncTrigger::Scheduled, null, $queued->id));

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame('not_syncable', $queued->fresh()->failure_code);
    }

    public function test_a_token_past_its_expiry_makes_no_provider_call_and_moves_the_connection_to_expired(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->subMinute()]);

        $this->assertRefusedWithoutProviderCall($account);

        $connection = $connection->fresh();
        $this->assertSame(MetaConnectionState::Expired, $connection->state, 'a dead token never leaves the connection looking active');
        $this->assertNull($connection->access_token_encrypted);
    }

    public function test_a_different_meta_user_makes_no_provider_call(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['meta_user_id' => '88888888']);

        $this->assertRefusedWithoutProviderCall($account);
        $this->assertSame(MetaConnectionState::Active, $connection->fresh()->state, 'a mismatch is refused, the connection itself is not touched');
    }

    public function test_an_account_without_a_recorded_selecting_user_fails_closed(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_meta_user_id' => null]);

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_every_eligibility_reason_is_reported(): void
    {
        $eligibility = app(MetaAdsSyncEligibility::class);

        $this->assertSame(MetaAdsSyncEligibility::REASON_ACCOUNT_MISSING, $eligibility->evaluate(987654)->reason);

        [$business, $connection, $account] = $this->syncableAccount();
        $this->assertTrue($eligibility->evaluate($account->id)->isAllowed());

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => null]);
        $this->assertSame(MetaAdsSyncEligibility::REASON_ACCOUNT_NOT_SELECTED, $eligibility->evaluate($account->id)->reason);
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['selected_at' => now()]);

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);
        $this->assertSame(MetaAdsSyncEligibility::REASON_BUSINESS_INACTIVE, $eligibility->evaluate($account->id)->reason);
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        DB::table('workspaces')->where('id', $business->workspace_id)->update(['is_active' => false]);
        $this->assertSame(MetaAdsSyncEligibility::REASON_WORKSPACE_INACTIVE, $eligibility->evaluate($account->id)->reason);
        DB::table('workspaces')->where('id', $business->workspace_id)->update(['is_active' => true]);

        StubbedMetaSyncEligibility::$deniedBusinessIds = [(int) $business->id];
        $this->assertSame(MetaAdsSyncEligibility::REASON_NOT_ENTITLED, $eligibility->evaluate($account->id)->reason);
        StubbedMetaSyncEligibility::$deniedBusinessIds = [];

        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->subMinute()]);
        $expired = $eligibility->evaluate($account->id);
        $this->assertSame(MetaAdsSyncEligibility::REASON_TOKEN_EXPIRED, $expired->reason);
        $this->assertSame($connection->id, $expired->connection->id);
        $this->assertSame($connection->id, $eligibility->evaluate($account->id)->connection->id);
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->addDays(30)]);

        DB::table('business_meta_connections')->where('id', $connection->id)->update(['meta_user_id' => 'someone-else']);
        $this->assertSame(MetaAdsSyncEligibility::REASON_CONNECTION_MISMATCH, $eligibility->evaluate($account->id)->reason);
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['meta_user_id' => $account->selected_meta_user_id]);

        DB::table('business_meta_connections')->where('id', $connection->id)->update(['state' => MetaConnectionState::Disconnected->value, 'access_token_encrypted' => null]);
        $this->assertSame(MetaAdsSyncEligibility::REASON_CONNECTION_INACTIVE, $eligibility->evaluate($account->id)->reason);
    }

    public function test_the_default_entitlement_check_goes_through_ads_feature_access(): void
    {
        // Not the stub: the production class, whose only extra dependency is AdsFeatureAccess.
        $this->app->bind(MetaAdsSyncEligibility::class, MetaAdsSyncEligibility::class);
        [, , $account] = $this->syncableAccount();

        $result = app(MetaAdsSyncEligibility::class)->evaluate($account->id);

        // Whatever the registry says for this lane, the answer is a clean allowed / not_entitled, never an error.
        $this->assertContains($result->reason, [null, MetaAdsSyncEligibility::REASON_NOT_ENTITLED]);
    }

    public function test_a_deleted_account_is_a_quiet_no_op(): void
    {
        SyncMetaAdsAccount::dispatchSync(987654);

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(0, MetaAdsSyncRun::query()->count());
    }
}
