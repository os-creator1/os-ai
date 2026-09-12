<?php

namespace Tests\Feature\Automations\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\RedispatchHeldEnrollments;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §6.3 — pause holds, and Resume restarts held work IMMEDIATELY.
 *
 * The contract is emphatic that Resume must not lean on the stale-active recovery
 * sweep: fifteen minutes is a safety net for lost jobs, and a customer who clicks
 * Resume should see work continue at once. The decisive test here freezes the
 * clock at the moment of resume, so nothing can be attributed to elapsed time.
 */
class PauseResumeTest extends TestCase
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

    private function lifecycle(): WorkflowLifecycle
    {
        return app(WorkflowLifecycle::class);
    }

    /** @return array{0: \App\Models\AutomationWorkflow, 1: AutomationEnrollment} */
    private function pausedMidJourney(): array
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [
            $this->recordedStep('first'),
            $this->recordedStep('second'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        $this->lifecycle()->pause($workflow->fresh());

        return [$workflow->fresh(), $enrollment->fresh()];
    }

    public function test_the_contract_resolves_to_the_runtime_lifecycle(): void
    {
        $this->assertInstanceOf(
            \App\Library\Automation\Workflow\Runtime\WorkflowLifecycleService::class,
            $this->lifecycle(),
        );
    }

    /** Pause stops execution and leaves the journey exactly where it stood. */
    public function test_pause_prevents_execution_and_holds_the_journey_untouched(): void
    {
        [, $enrollment] = $this->pausedMidJourney();

        $before = $enrollment->only(['status', 'current_node_id', 'step_count']);

        app(WorkflowAdvancer::class)->advance($enrollment);

        $after = $enrollment->fresh();

        $this->assertSame(0, $this->executor->callCount(), 'A paused workflow must execute nothing.');
        $this->assertSame(
            0,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
            'A held journey must not even claim a step.',
        );
        $this->assertSame($before, $after->only(['status', 'current_node_id', 'step_count']));
        $this->assertSame(EnrollmentStatus::Active, $after->status, 'Held is an ACTIVE enrollment nobody is running.');
    }

    /**
     * THE CENTRAL ONE. Resume dispatches the contracted re-dispatch job after
     * commit, and the held journey continues — with the clock frozen, so it cannot
     * possibly be the fifteen-minute recovery sweep doing the work.
     */
    public function test_resume_immediately_redispatches_held_work_without_the_recovery_threshold(): void
    {
        [$workflow, $enrollment] = $this->pausedMidJourney();

        Carbon::setTestNow(Carbon::now());
        Bus::fake([RedispatchHeldEnrollments::class, AdvanceWorkflowEnrollment::class]);

        $this->lifecycle()->resume($workflow);

        // Resume must go through the contracted re-dispatch path rather than
        // leaving the work for the recovery sweep.
        Bus::assertDispatched(RedispatchHeldEnrollments::class);
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);

        // Now run that job for real: it must enqueue an advance for the held one.
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        (new RedispatchHeldEnrollments((int) $workflow->id))->handle();

        Bus::assertDispatched(AdvanceWorkflowEnrollment::class);

        // And the work actually completes, still at the frozen instant.
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(2, $this->executor->callCount());
        $this->assertTrue(
            Carbon::now()->equalTo(Carbon::getTestNow()),
            'No time passed: the resume path cannot have depended on the recovery threshold.',
        );

        Carbon::setTestNow();
    }

    /** The recovery sweep is not involved in resume, and skips paused workflows. */
    public function test_the_recovery_sweep_ignores_held_journeys_of_a_paused_workflow(): void
    {
        [, $enrollment] = $this->pausedMidJourney();

        // Make it look long-stalled, then sweep.
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'last_advanced_at' => Carbon::now()->subDay(),
            'enrolled_at' => Carbon::now()->subDay(),
        ]);

        Queue::fake();

        $counts = app(WorkflowRecoveryService::class)->recoverStalled();

        $this->assertSame(
            ['redispatched' => 0, 'failed' => 0, 'expired' => 0],
            $counts,
            'Recovery must leave a paused workflow\'s held journeys entirely alone.',
        );
        Queue::assertNothingPushed();
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);
    }

    /** Resume is idempotent: clicking twice cannot double-run a step. */
    public function test_resuming_twice_does_not_execute_anything_twice(): void
    {
        [$workflow, $enrollment] = $this->pausedMidJourney();

        $this->lifecycle()->resume($workflow);
        $this->lifecycle()->resume($workflow->fresh());

        // Both re-dispatches land as advance work; run it repeatedly.
        for ($i = 0; $i < 4; $i++) {
            app(WorkflowAdvancer::class)->advance($enrollment->fresh() ?? $enrollment);
        }

        $this->assertSame(2, $this->executor->callCount(), 'Each step still runs exactly once.');
        $this->assertSame(
            4,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
        );
    }

    /** Resume never touches a waiting journey. */
    public function test_resume_leaves_waiting_enrollments_alone(): void
    {
        [$workflow, $enrollment] = $this->pausedMidJourney();

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'status' => EnrollmentStatus::Waiting->value,
            'resume_at' => Carbon::now()->addDays(3),
        ]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $this->lifecycle()->resume($workflow);
        (new RedispatchHeldEnrollments((int) $workflow->id))->handle();

        Bus::assertNotDispatched(
            AdvanceWorkflowEnrollment::class,
            'A journey parked on a future wait must not be re-dispatched by Resume.',
        );

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->status);
        $this->assertNotNull($enrollment->resume_at);
    }

    /** Pausing again before the re-dispatch runs simply holds it once more. */
    public function test_a_workflow_paused_again_before_redispatch_stays_held(): void
    {
        [$workflow, $enrollment] = $this->pausedMidJourney();

        // Fake BEFORE resuming. On the sync queue a real dispatch would run the
        // re-dispatch inline and the journey would finish during resume() itself,
        // which is the very window this test is about.
        Bus::fake([RedispatchHeldEnrollments::class, AdvanceWorkflowEnrollment::class]);

        $this->lifecycle()->resume($workflow);
        Bus::assertDispatched(RedispatchHeldEnrollments::class);

        // Paused again before the queued job got its turn.
        $this->lifecycle()->pause($workflow->fresh());

        (new RedispatchHeldEnrollments((int) $workflow->id))->handle();

        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);
        $this->assertSame(0, $this->executor->callCount());
    }

    /** Archiving cancels journeys in flight; stop-all does the same on demand. */
    public function test_archive_and_stop_all_cancel_journeys_in_flight(): void
    {
        [$workflow, $enrollment] = $this->pausedMidJourney();

        $this->lifecycle()->archive($workflow);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->status);
        $this->assertSame('workflow_archived', $enrollment->exit_reason);
        $this->assertNull($enrollment->current_node_id);
        $this->assertSame(WorkflowStatus::Archived, $workflow->fresh()->status);

        // A cancelled journey stays cancelled even if something dispatches it.
        app(WorkflowAdvancer::class)->advance($enrollment);
        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->fresh()->status);
        $this->assertSame(0, $this->executor->callCount());
    }

    public function test_stop_all_reports_what_it_cancelled(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('a'), $this->endStep()]);

        foreach (range(1, 3) as $i) {
            $contact = $this->contactFor($business, 'Stop' . $i);
            app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        }

        $cancelled = $this->lifecycle()->stopAllActive($workflow->fresh(), 'stopped_by_user');

        $this->assertSame(3, $cancelled);
        $this->assertSame(
            3,
            AutomationEnrollment::query()->where('status', EnrollmentStatus::Cancelled->value)->count(),
        );
    }
}
