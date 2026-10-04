<?php

namespace Tests\Feature\GoogleAds\Sync;

use App\Enums\Business\BusinessStatus;
use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Jobs\Base;
use App\Jobs\GoogleAds\SyncGoogleAdsAccount;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Library\GoogleAds\Sync\GoogleAdsSyncDispatcher;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\GoogleAds\Sync\Concerns\PreparesAdsSync;
use Tests\Feature\GoogleAds\Sync\Support\StubbedSyncEligibility;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §5 — the SyncGoogleAdsAccount job: re-checks
 * before any provider call, one-at-a-time claim, claim always released.
 */
class SyncGoogleAdsAccountJobTest extends TestCase
{
    use PreparesAdsSync;
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
    private function runJob(SyncGoogleAdsAccount $job): void
    {
        app()->call([$job, 'handle']);
    }

    private function latestRun(GoogleAdsAccount $account): GoogleAdsSyncRun
    {
        return GoogleAdsSyncRun::query()->where('google_ads_account_id', $account->id)->latest('id')->firstOrFail();
    }

    public function test_the_job_extends_base_and_never_retries(): void
    {
        $job = new SyncGoogleAdsAccount(1);

        $this->assertInstanceOf(Base::class, $job);
        $this->assertSame(1, $job->tries);
    }

    public function test_the_job_runs_a_full_sync_and_releases_the_claim(): void
    {
        [, , $account] = $this->syncableAccount();

        SyncGoogleAdsAccount::dispatchSync($account->id, GoogleAdsSyncTrigger::Connect, (int) \App\Models\User::query()->value('id'));

        $run = $this->latestRun($account);
        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(GoogleAdsSyncTrigger::Connect, $run->trigger);
        $this->assertGreaterThan(0, DB::table('google_ads_campaigns')->where('google_ads_account_id', $account->id)->count());
        $this->assertNull($account->fresh()->sync_claimed_at);
    }

    public function test_a_queued_run_is_the_one_the_job_executes(): void
    {
        [, , $account] = $this->syncableAccount();
        Bus::fake();
        $actor = (int) \App\Models\User::query()->value('id');
        $queued = app(GoogleAdsSyncDispatcher::class)->queue($account, GoogleAdsSyncTrigger::Manual, $actor);
        Bus::assertDispatched(SyncGoogleAdsAccount::class);

        $this->runJob(new SyncGoogleAdsAccount($account->id, GoogleAdsSyncTrigger::Manual, $actor, $queued->id));

        $this->assertSame(1, GoogleAdsSyncRun::query()->where('google_ads_account_id', $account->id)->count());
        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $queued->fresh()->state);
    }

    public function test_a_run_that_is_no_longer_queued_is_not_executed_again(): void
    {
        [, , $account] = $this->syncableAccount();
        $run = app(GoogleAdsSyncDispatcher::class)->createQueuedRun($account, GoogleAdsSyncTrigger::Manual);
        $run->forceFill(['state' => GoogleAdsSyncRunState::Failed])->save();

        SyncGoogleAdsAccount::dispatchSync($account->id, GoogleAdsSyncTrigger::Manual, null, $run->id);

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame(GoogleAdsSyncRunState::Failed, $run->fresh()->state);
    }

    // ------------------------------------------------------------------
    // Claim
    // ------------------------------------------------------------------

    public function test_a_live_claim_makes_the_job_skip_without_any_provider_call(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(2)]);

        SyncGoogleAdsAccount::dispatchSync($account->id);

        $run = $this->latestRun($account);
        $this->assertSame(GoogleAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('already_running', $run->failure_code);
        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertNotNull($account->fresh()->sync_claimed_at, 'another worker owns the claim; this job must not release it');
    }

    public function test_a_stale_claim_is_taken_over(): void
    {
        [, , $account] = $this->syncableAccount();
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(16)]);

        SyncGoogleAdsAccount::dispatchSync($account->id);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $this->latestRun($account)->state);
        $this->assertNull($account->fresh()->sync_claimed_at);
    }

    public function test_two_concurrent_syncs_for_one_account_run_the_provider_once(): void
    {
        [, , $account] = $this->syncableAccount();
        $fired = false;
        $this->hook->before = function (string $method) use (&$fired, $account): void {
            if ($method === 'campaigns' && ! $fired) {
                $fired = true;
                // A second worker starts while the first is mid-sync.
                SyncGoogleAdsAccount::dispatchSync($account->id, GoogleAdsSyncTrigger::Manual);
            }
        };

        SyncGoogleAdsAccount::dispatchSync($account->id);

        $this->assertSame(1, $this->fakeAds->callCount('campaigns'));
        $this->assertSame(1, $this->fakeAds->callCount('customerDetails'));
        $states = GoogleAdsSyncRun::query()->where('google_ads_account_id', $account->id)->orderBy('id')->pluck('state')->map->value->all();
        $this->assertSame(['succeeded', 'skipped'], $states);
        $this->assertNull($account->fresh()->sync_claimed_at, 'the first job released the claim at the end');
    }

    public function test_the_claim_is_released_after_a_provider_failure_which_does_not_crash_the_job(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeAds->failNext('campaigns', GoogleAdsProviderException::rateLimited());

        SyncGoogleAdsAccount::dispatchSync($account->id);

        $this->assertSame(GoogleAdsSyncRunState::Skipped, $this->latestRun($account)->state);
        $this->assertNull($account->fresh()->sync_claimed_at);
    }

    public function test_the_claim_is_released_after_a_programming_error_which_is_not_swallowed(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->hook->before = function (string $method): void {
            if ($method === 'adGroups') {
                throw new RuntimeException('boom');
            }
        };

        try {
            SyncGoogleAdsAccount::dispatchSync($account->id);
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

    private function assertRefusedWithoutProviderCall(GoogleAdsAccount $account): void
    {
        SyncGoogleAdsAccount::dispatchSync($account->id);

        $run = $this->latestRun($account);
        $this->assertSame(GoogleAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('not_syncable', $run->failure_code);
        $this->assertSame(0, $this->fakeAds->callCount(), 'no provider call of any kind');
        $this->assertNull($account->fresh()->sync_claimed_at);
        $this->assertSame(0, DB::table('google_ads_campaigns')->where('google_ads_account_id', $account->id)->count());
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
        StubbedSyncEligibility::$deniedBusinessIds = [(int) $business->id];

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_a_revoked_or_disconnected_connection_makes_no_provider_call(): void
    {
        foreach ([GoogleConnectionState::Revoked, GoogleConnectionState::Disconnected] as $state) {
            [, $connection, $account] = $this->syncableAccount('Business ' . $state->value, PhotoBoothFixture::CUSTOMER_ID);
            DB::table('business_google_connections')->where('id', $connection->id)->update(['state' => $state->value, 'refresh_token_encrypted' => null]);

            $this->assertRefusedWithoutProviderCall($account);
        }
    }

    public function test_a_connection_revoked_after_queueing_makes_no_provider_call(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        Bus::fake();
        $queued = app(GoogleAdsSyncDispatcher::class)->queue($account, GoogleAdsSyncTrigger::Scheduled);
        DB::table('business_google_connections')->where('id', $connection->id)->update(['state' => GoogleConnectionState::Revoked->value, 'refresh_token_encrypted' => null]);

        $this->runJob(new SyncGoogleAdsAccount($account->id, GoogleAdsSyncTrigger::Scheduled, null, $queued->id));

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame('not_syncable', $queued->fresh()->failure_code);
    }

    public function test_an_account_bound_to_a_non_ads_connection_makes_no_provider_call(): void
    {
        [$business, , $account] = $this->syncableAccount();
        $other = BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'product' => GoogleConnectionProduct::BusinessProfile,
            'state' => GoogleConnectionState::Active,
            'refresh_token_encrypted' => 'gbp-token',
        ]);
        DB::table('google_ads_accounts')->where('id', $account->id)->update(['business_google_connection_id' => $other->id]);

        $this->assertRefusedWithoutProviderCall($account);
    }

    public function test_a_deleted_account_is_a_quiet_no_op(): void
    {
        SyncGoogleAdsAccount::dispatchSync(987654);

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame(0, GoogleAdsSyncRun::query()->count());
    }
}
