<?php

namespace Tests\Feature\QueryBudget;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Listeners\Support\ResetRequestScopedCacheAtJobBoundary;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\QueryBudget\Support\TenancyProbeJob;
use Tests\TestCase;

/**
 * RequestScopedCache at the queue-job boundary.
 *
 * A `queue:work` daemon keeps ONE console Request bound for its whole life, and
 * RequestScopedCache memoizes on the bound Request. These tests reproduce that
 * condition exactly: real jobs on the real `database` queue, run one after
 * another by Laravel's real worker, in the same process and container. Between
 * jobs the world changes the way it does in production — another process writes
 * the row directly — so nothing in the job's own process invalidates the memo.
 *
 * No test clears the cache itself before asserting. If the boundary did not
 * hold, the second job would read the first job's memo.
 */
class RequestScopedCacheQueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenancyProbeJob::$observed = [];
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function admin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /** @return array{workspace: Workspace, business: Business, owner: User} */
    private function entitledWorkspace(): array
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'Lifecycle Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Lifecycle Business', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->admin(), 'Fixture assignment.', true, 0);

        return ['workspace' => $workspace->fresh(), 'business' => $business->fresh(), 'owner' => $owner];
    }

    private function push(string $label, array $fixture, bool $failAfterReading = false): void
    {
        Queue::connection('database')->push(new TenancyProbeJob(
            $label,
            (int) $fixture['workspace']->id,
            (int) $fixture['business']->id,
            (int) $fixture['owner']->id,
            $this->admin(),
            $failAfterReading,
        ));
    }

    /** One job, processed by Laravel's real worker, in this process. */
    private function workOne(): void
    {
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));
    }

    private function observed(string $label): array
    {
        $this->assertArrayHasKey($label, TenancyProbeJob::$observed, "Job '{$label}' did not run.");

        return TenancyProbeJob::$observed[$label];
    }

    // =================================================================
    // The security regression
    // =================================================================

    public function test_a_plan_suspended_between_two_jobs_denies_the_second_job(): void
    {
        $fixture = $this->entitledWorkspace();

        // JOB 1 — plan active, decision allowed, memo populated.
        $this->push('job-1', $fixture);
        $this->workOne();

        $this->assertTrue($this->observed('job-1')['allowed']);
        $this->assertTrue($this->observed('job-1')['memoized_after_read'], 'Precondition: job 1 really did memoize the plan read.');

        // Between jobs — suspended by someone else, not through this process's repository.
        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $fixture['workspace']->id)
            ->update(['status' => WorkspacePlanAssignmentStatus::Suspended->value]);

        // JOB 2 — same worker, same container, no clearing in between.
        $this->push('job-2', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-2')['allowed'], 'A suspended plan must deny the next job — never a memoized "allowed" from the job before.');
        $this->assertSame('plan_suspended', $this->observed('job-2')['reason']);
    }

    public function test_a_deny_override_added_between_jobs_is_seen_by_the_next_job(): void
    {
        $fixture = $this->entitledWorkspace();

        $this->push('job-1', $fixture);
        $this->workOne();
        $this->assertTrue($this->observed('job-1')['allowed']);

        DB::table('workspace_entitlement_overrides')->insert([
            'workspace_id' => $fixture['workspace']->id,
            'feature_key' => PlatformFeature::Crm->value,
            'state' => WorkspaceEntitlementOverrideState::Deny->value,
            'reason' => 'Between-jobs override.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->push('job-2', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-2')['allowed']);
        $this->assertSame('denied_by_workspace_override', $this->observed('job-2')['reason']);
    }

    public function test_a_business_feature_toggle_disabled_between_jobs_is_seen_by_the_next_job(): void
    {
        $fixture = $this->entitledWorkspace();

        $this->push('job-1', $fixture);
        $this->workOne();
        $this->assertTrue($this->observed('job-1')['allowed']);

        DB::table('business_feature_toggles')->insert([
            'business_id' => $fixture['business']->id,
            'feature_key' => PlatformFeature::Crm->value,
            'reason' => 'Between-jobs disable.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->push('job-2', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-2')['allowed']);
        $this->assertSame('disabled_for_business', $this->observed('job-2')['reason']);
    }

    public function test_a_workspace_deactivated_between_jobs_denies_business_access_in_the_next_job(): void
    {
        $fixture = $this->entitledWorkspace();

        $this->push('job-1', $fixture);
        $this->workOne();
        $this->assertTrue($this->observed('job-1')['can_access']);

        DB::table('workspaces')->where('id', $fixture['workspace']->id)->update(['is_active' => false]);

        $this->push('job-2', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-2')['can_access'], 'A deactivated Workspace must deny access in the next job.');
    }

    public function test_a_business_reassigned_between_jobs_is_not_accessible_to_the_old_owner_in_the_next_job(): void
    {
        $fixture = $this->entitledWorkspace();
        $newHome = $this->entitledWorkspace();

        $this->push('job-1', $fixture);
        $this->workOne();
        $this->assertTrue($this->observed('job-1')['can_access']);

        // The Business now belongs to another customer, in another Workspace.
        DB::table('businesses')->where('id', $fixture['business']->id)->update([
            'workspace_id' => $newHome['workspace']->id,
            'customer_id' => $newHome['owner']->id,
        ]);

        $this->push('job-2', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-2')['can_access'], 'The previous owner must not keep access through a memoized Business row.');
        $this->assertFalse($this->observed('job-2')['allowed'], 'Nor may a stale Business row keep the entitlement decision allowed.');
    }

    // =================================================================
    // Both edges of a job, including failure
    // =================================================================

    public function test_a_failed_job_still_leaves_the_next_job_clean(): void
    {
        $fixture = $this->entitledWorkspace();
        $cache = app(RequestScopedCache::class);

        $threw = 0;
        Event::listen(JobExceptionOccurred::class, function () use (&$threw): void {
            $threw++;
        });

        // JOB 1 reads (and memoizes), then throws.
        $this->push('job-1', $fixture, failAfterReading: true);
        $this->workOne();

        $this->assertTrue($this->observed('job-1')['allowed']);
        $this->assertTrue($this->observed('job-1')['memoized_after_read']);
        $this->assertSame(1, $threw, 'Sanity: the worker really went down its exception path for job 1.');

        // A failed job leaves nothing behind — even before another job starts.
        $this->assertFalse($cache->has("workspace_plan_assignment:find:{$fixture['workspace']->id}"));

        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $fixture['workspace']->id)
            ->update(['status' => WorkspacePlanAssignmentStatus::Suspended->value]);

        $this->push('job-2', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-2')['allowed']);
        $this->assertSame('plan_suspended', $this->observed('job-2')['reason']);
    }

    public function test_every_worker_job_starts_with_an_empty_cache(): void
    {
        $fixture = $this->entitledWorkspace();
        $cache = app(RequestScopedCache::class);

        // Something earlier in this long-lived process memoized the very reads
        // the job will make (a previous command body, a bootstrap step).
        app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $fixture['workspace']->id);
        $this->assertTrue($cache->has("workspace_plan_assignment:find:{$fixture['workspace']->id}"), 'Precondition: the console Request holds a memo.');

        $this->push('job-1', $fixture);
        $this->workOne();

        $this->assertFalse($this->observed('job-1')['memoized_at_start'], 'A worker job must begin with nothing memoized.');
    }

    public function test_a_successful_job_leaves_nothing_behind(): void
    {
        $fixture = $this->entitledWorkspace();

        $this->push('job-1', $fixture);
        $this->workOne();

        $this->assertTrue($this->observed('job-1')['memoized_after_read']);
        $this->assertFalse(app(RequestScopedCache::class)->has("workspace_plan_assignment:find:{$fixture['workspace']->id}"));
        $this->assertFalse(app(RequestScopedCache::class)->has("business:find:{$fixture['business']->id}"));
    }

    public function test_two_jobs_in_one_worker_never_share_a_memoized_value(): void
    {
        $fixture = $this->entitledWorkspace();

        $this->push('job-1', $fixture);
        $this->push('job-2', $fixture);

        $this->workOne();
        $this->workOne();

        $this->assertFalse($this->observed('job-1')['memoized_at_start']);
        $this->assertFalse($this->observed('job-2')['memoized_at_start'], 'Job 2 must not inherit job 1\'s memo.');
        $this->assertTrue($this->observed('job-2')['memoized_after_read'], 'Inside one job the optimization still works.');
    }

    // =================================================================
    // What must NOT change
    // =================================================================

    /**
     * A sync job runs inside its dispatcher — normally an HTTP request — and
     * must not discard that request's memo mid-request.
     */
    public function test_a_sync_job_inside_a_request_keeps_that_requests_memo(): void
    {
        $fixture = $this->entitledWorkspace();
        $cache = app(RequestScopedCache::class);

        $this->app->instance('request', Request::create('/an-http-request'));
        $cache->remember('request:only:fact', fn () => 'still useful');

        TenancyProbeJob::dispatchSync(
            'sync-job',
            (int) $fixture['workspace']->id,
            (int) $fixture['business']->id,
            (int) $fixture['owner']->id,
            $this->admin(),
        );

        $this->assertTrue($this->observed('sync-job')['allowed']);
        $this->assertTrue($cache->has('request:only:fact'), 'A sync job must not flush the request that dispatched it.');
    }

    public function test_the_boundary_is_one_global_listener_on_the_queue_events(): void
    {
        Event::fake();

        Event::assertListening(JobProcessing::class, ResetRequestScopedCacheAtJobBoundary::class);
        Event::assertListening(JobProcessed::class, ResetRequestScopedCacheAtJobBoundary::class);
        Event::assertListening(JobExceptionOccurred::class, ResetRequestScopedCacheAtJobBoundary::class);
    }
}
