<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowLocationCheckpointState;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowLocationAdmission;
use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Library\Business\BusinessLocationManager;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Location run-scope foundation — runtime checkpoint correction (independent
 * review, pre-merge finding #2): `WorkflowCheckpoint` must re-check the
 * PINNED enrollment Location, live, before every step — never the Contact's
 * current Location — and must HOLD an archived-Location run rather than
 * letting it keep executing sensitive actions, while a Location that can
 * never be proven at all must EXIT honestly instead of holding forever.
 */
class WorkflowLocationCheckpointTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    private RecordingNodeExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = $this->recordingExecutor();
    }

    /** @return array{0: Customer, 1: Business, 2: BusinessLocation, 3: BusinessLocation} */
    private function twoLocationTenant(): array
    {
        [$customer, $business] = $this->entitledTenant();
        $locationA = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $locationB = $this->businessLocation($business);

        return [$customer, $business, $locationA, $locationB];
    }

    private function locations(): BusinessLocationManager
    {
        return app(BusinessLocationManager::class);
    }

    /**
     * A two-step run with a WAIT between the steps, so a test can park it
     * mid-journey (after the first sensitive action, before the second) and
     * act on its pinned Location while it sits there — exactly the window
     * the review finding describes.
     *
     * @return array{0: AutomationEnrollment, 1: BusinessLocation, 2: Customer}
     */
    private function enrolledAtLocationA(): array
    {
        [$customer, $business, $locationA] = $this->twoLocationTenant();
        [$workflow] = $this->publishWorkflow($business, [
            $this->recordedStep('first'),
            ['key' => (string) Str::uuid(), 'type' => 'wait', 'config' => ['mode' => 'duration', 'amount' => 1, 'unit' => 'days']],
            $this->recordedStep('second'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);

        return [$enrollment->fresh(), $locationA, $customer];
    }

    /** Park a journey at its wait, make the wait due, and wake it — WITHOUT running the advancer again yet. */
    private function parkThenWake(AutomationEnrollment $enrollment): void
    {
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());
        $this->assertTrue($enrollment->fresh()->isWaiting(), 'Sanity: the run must actually be parked at the wait for this proof to mean anything.');

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['resume_at' => now()->subMinute()]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);
        app(WorkflowWakeService::class)->wakeDue();
    }

    // =================================================================
    // WorkflowLocationAdmission::checkpointState() — the pure predicate
    // =================================================================

    public function test_checkpoint_state_is_active_for_a_live_same_business_location(): void
    {
        [, $business, $locationA] = $this->twoLocationTenant();

        $state = app(WorkflowLocationAdmission::class)->checkpointState((int) $locationA->id, (int) $business->id);

        $this->assertSame(WorkflowLocationCheckpointState::Active, $state);
    }

    public function test_checkpoint_state_is_unresolved_for_a_null_location(): void
    {
        [, $business] = $this->twoLocationTenant();

        $state = app(WorkflowLocationAdmission::class)->checkpointState(null, (int) $business->id);

        $this->assertSame(WorkflowLocationCheckpointState::Unresolved, $state);
    }

    public function test_checkpoint_state_is_unresolved_for_a_nonexistent_location_id(): void
    {
        [, $business] = $this->twoLocationTenant();

        $state = app(WorkflowLocationAdmission::class)->checkpointState(999999999, (int) $business->id);

        $this->assertSame(WorkflowLocationCheckpointState::Unresolved, $state);
    }

    public function test_checkpoint_state_is_unresolved_for_a_foreign_business_location(): void
    {
        [, $business, $locationA] = $this->twoLocationTenant();
        [, $otherBusiness] = $this->entitledTenant();

        $state = app(WorkflowLocationAdmission::class)->checkpointState((int) $locationA->id, (int) $otherBusiness->id);

        $this->assertSame(WorkflowLocationCheckpointState::Unresolved, $state);
    }

    public function test_checkpoint_state_is_archived_for_an_archived_same_business_location(): void
    {
        [, $business, $locationA] = $this->twoLocationTenant();
        $locationA->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value])->save();

        $state = app(WorkflowLocationAdmission::class)->checkpointState((int) $locationA->id, (int) $business->id);

        $this->assertSame(WorkflowLocationCheckpointState::Archived, $state);
    }

    // =================================================================
    // WorkflowCheckpoint at the runtime boundary — the actual protection
    // =================================================================

    /** The baseline: an Active pinned Location must not change anything. */
    public function test_a_run_at_an_active_pinned_location_proceeds_normally(): void
    {
        [$enrollment] = $this->enrolledAtLocationA();

        $this->parkThenWake($enrollment);
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(2, $this->executor->callCount());
        $this->assertTrue($enrollment->fresh()->isTerminal());
    }

    /**
     * THE CENTRAL ONE. A Location archived mid-journey must HOLD the run —
     * exactly like `PauseResumeTest`'s paused-workflow proof — not let a
     * further sensitive action execute, and not touch the cursor.
     */
    public function test_an_archived_pinned_location_holds_the_run_and_executes_nothing_further(): void
    {
        [$enrollment, $locationA, $customer] = $this->enrolledAtLocationA();

        // Park mid-journey (first step done, sitting on the wait), THEN
        // archive the run's own Location, THEN wake it — so the archived
        // Location is exactly what the post-wake checkpoint must catch
        // before the second sensitive action runs.
        $this->parkThenWake($enrollment);
        $this->assertSame(1, $this->executor->callCount());

        $this->locations()->archiveLocation($locationA->fresh(), (int) $customer->user_id);

        $before = $enrollment->fresh()->only(['status', 'current_node_id', 'step_count']);

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $after = $enrollment->fresh();

        $this->assertSame(1, $this->executor->callCount(), 'An archived Location must stop the next sensitive action from running.');
        $this->assertSame($before, $after->only(['status', 'current_node_id', 'step_count']), 'A held journey must be left exactly where it was.');
        $this->assertSame(
            0,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->where('node_id', $after->current_node_id)->count(),
            'A held journey must not even claim the step.',
        );
    }

    /** Reactivating the pinned Location must let the held run continue. */
    public function test_reactivating_the_pinned_location_resumes_the_held_run(): void
    {
        [$enrollment, $locationA, $customer] = $this->enrolledAtLocationA();

        $this->parkThenWake($enrollment);
        $this->locations()->archiveLocation($locationA->fresh(), (int) $customer->user_id);
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());
        $this->assertSame(1, $this->executor->callCount(), 'Sanity: the run must actually be held for this proof to mean anything.');

        $this->locations()->reactivateLocation($locationA->fresh(), (int) $customer->user_id);

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(2, $this->executor->callCount());
        $this->assertTrue($enrollment->fresh()->isTerminal());
    }

    /**
     * Fail-closed: a historical enrollment this lane's own backfill could
     * not resolve (`business_location_id` left NULL) must EXIT honestly
     * rather than hold forever unresumable.
     */
    public function test_a_null_pinned_location_exits_the_run(): void
    {
        [$enrollment] = $this->enrolledAtLocationA();

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['business_location_id' => null]);

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $after = $enrollment->fresh();
        $this->assertSame(0, $this->executor->callCount(), 'An unresolved Location must never let a sensitive action run.');
        $this->assertTrue($after->isTerminal());
        $this->assertSame('location_unresolved', $after->exit_reason);
    }

    /**
     * A pinned Location id that resolves, but to a DIFFERENT Business's row,
     * must also EXIT. The FK on `business_location_id` guarantees the id
     * always names a real `business_locations` row, so a foreign-Business
     * row is the reachable shape of "no longer resolves for this run."
     */
    public function test_a_pinned_location_belonging_to_a_different_business_exits_the_run(): void
    {
        [$enrollment] = $this->enrolledAtLocationA();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignLocation = BusinessLocation::query()->where('business_id', $otherBusiness->id)->firstOrFail();

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['business_location_id' => $foreignLocation->id]);

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $after = $enrollment->fresh();
        $this->assertSame(0, $this->executor->callCount());
        $this->assertTrue($after->isTerminal());
        $this->assertSame('location_unresolved', $after->exit_reason);
    }

    /**
     * A Contact transfer must never be consulted here: the checkpoint uses
     * the enrollment's own pinned `business_location_id`, exactly as §13
     * requires, never the Contact's current Location.
     */
    public function test_a_contact_transfer_after_enrollment_does_not_affect_the_checkpoint(): void
    {
        [$enrollment, $locationA] = $this->enrolledAtLocationA();
        $locationB = $this->businessLocation($locationA->business);

        $this->parkThenWake($enrollment);

        // The Contact moves to a second Location of the SAME Business while
        // the run sits parked — the checkpoint must not even look at this,
        // let alone be swayed by it.
        $contact = Contacts::query()->findOrFail($enrollment->fresh()->contact_id);
        $contact->forceFill(['location_id' => $locationB->id])->save();

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(2, $this->executor->callCount(), 'The run must keep executing against its own pinned Location, unaffected by the Contact moving.');
        $this->assertTrue($enrollment->fresh()->isTerminal());
    }

    /**
     * Conservative SMS recovery (B4 §5.1) must stay untouched by this lane:
     * an interrupted external step is still never re-run, regardless of the
     * Location check added here.
     */
    public function test_an_interrupted_external_step_is_still_never_retried_after_the_location_check(): void
    {
        [$customer, $business, $locationA] = $this->twoLocationTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('external'), $this->endStep()]);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $locationA->id])->save();
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);

        $externalNodeId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $enrollment->version_id)
            ->where('node_type', 'send_sms')
            ->value('id');

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['current_node_id' => $externalNodeId]);

        DB::table('automation_step_runs')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $externalNodeId,
            'node_type' => 'send_sms',
            'status' => 'started',
            'started_at' => now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
            'created_at' => now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
            'updated_at' => now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
        ]);
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'last_advanced_at' => now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
            'enrolled_at' => now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
        ]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);
        app(WorkflowRecoveryService::class)->recoverStalled();

        $this->assertSame(0, $this->executor->callCount(), 'The interrupted action must never run, with or without the new Location check.');
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class, 'An external step must never be re-dispatched, unaffected by the Location check.');
        $this->assertSame((int) $locationA->id, (int) $enrollment->fresh()->business_location_id, 'Recovery must never touch the pinned Location.');
    }
}
