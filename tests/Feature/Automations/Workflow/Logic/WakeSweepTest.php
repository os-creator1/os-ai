<?php

namespace Tests\Feature\Automations\Workflow\Logic;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\RedispatchHeldEnrollments;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §8.2 — the every-minute wake sweep.
 *
 * The sweep's whole job is to turn elapsed time into progress exactly once. Most
 * of these prove the "exactly once" half: repeated sweeps, paused workflows,
 * cancelled journeys and future waits must all leave the row alone or leave it
 * unchanged, and only a genuinely due wait may move.
 */
class WakeSweepTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    private RecordingNodeExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = $this->recordingExecutor();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function wake(): WorkflowWakeService
    {
        return app(WorkflowWakeService::class);
    }

    /** @return array{0: AutomationWorkflow, 1: AutomationEnrollment} */
    private function waitingJourney(int $minutes = 30): array
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [
            $this->waitStep($minutes, 'minutes'),
            $this->recordedStep('after the wait'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return [$workflow->fresh(), $enrollment->fresh()];
    }

    /** 11. A wait that is not yet due is not touched. */
    public function test_a_sweep_before_the_wait_is_due_does_nothing(): void
    {
        [, $enrollment] = $this->waitingJourney(30);
        $before = $this->storedResumeAt($enrollment);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->wake()->wakeDue();

        $this->assertSame(['woken' => 0, 'lost' => 0, 'examined' => 0], $counts);
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);
        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->fresh()->status);
        $this->assertEquals($before, $this->storedResumeAt($enrollment));
    }

    /** 12. The boundary is inclusive: due exactly now wakes now. */
    public function test_the_exact_due_boundary_wakes(): void
    {
        [, $enrollment] = $this->waitingJourney(30);

        $now = Carbon::now()->startOfSecond();
        $this->makeWaitDue($enrollment, $now);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->wake()->wakeDue($now);

        $this->assertSame(1, $counts['woken'], 'resume_at == now is due.');

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertNull($enrollment->resume_at, 'A woken journey carries no wake time.');
    }

    /** 12b. Waking completes the wait step run and moves past the wait node. */
    public function test_waking_completes_the_step_and_moves_the_cursor(): void
    {
        [, $enrollment] = $this->waitingJourney();
        $waitNodeId = (int) $enrollment->current_node_id;
        $this->makeWaitDue($enrollment);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $this->wake()->wakeDue();

        $stepRun = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)->where('node_id', $waitNodeId)->first();

        $this->assertSame(StepRunStatus::Succeeded->value, $stepRun->status);
        $this->assertNotNull($stepRun->completed_at);
        $this->assertNotSame(
            $waitNodeId,
            (int) $enrollment->fresh()->current_node_id,
            'The cursor must move past the wait, or the wait would be re-evaluated for ever.',
        );

        Bus::assertDispatched(AdvanceWorkflowEnrollment::class);
    }

    /** 13. Sweeping repeatedly cannot wake the same journey twice. */
    public function test_repeated_sweeps_do_not_duplicate_the_wake(): void
    {
        [, $enrollment] = $this->waitingJourney();
        $this->makeWaitDue($enrollment);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $first = $this->wake()->wakeDue();
        $second = $this->wake()->wakeDue();
        $third = $this->wake()->wakeDue();

        $this->assertSame(1, $first['woken']);
        $this->assertSame(0, $second['woken'], 'A woken journey is no longer waiting, so it is not selected again.');
        $this->assertSame(0, $third['woken']);
        $this->assertSame(1, Bus::dispatched(AdvanceWorkflowEnrollment::class)->count());
    }

    /** 15. The successor runs exactly once, however often the sweep runs. */
    public function test_the_successor_executes_exactly_once(): void
    {
        [, $enrollment] = $this->waitingJourney();
        $this->makeWaitDue($enrollment);

        // Real dispatch: on the sync queue the advance runs inline, which is the
        // whole path from "wait elapsed" to "next step done".
        $this->wake()->wakeDue();
        $this->wake()->wakeDue();

        $this->assertSame(1, $this->executor->callCount(), 'One elapsed wait, one execution of the next step.');
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    /**
     * 16. A paused workflow's wait still wakes — but executes nothing.
     *
     * This is the distinction the contract insists on: waking is bookkeeping,
     * and the checkpoint is the only thing that decides whether work may run.
     */
    public function test_a_paused_workflow_wakes_but_does_not_execute(): void
    {
        [$workflow, $enrollment] = $this->waitingJourney();
        $this->makeWaitDue($enrollment);

        app(WorkflowLifecycle::class)->pause($workflow);

        $this->wake()->wakeDue();

        $this->assertSame(0, $this->executor->callCount(), 'A paused workflow must execute nothing.');

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status, 'It is held, not lost.');
        $this->assertNotNull($enrollment->current_node_id);

        // And once resumed, the work it was owed proceeds.
        app(WorkflowLifecycle::class)->resume($workflow->fresh());
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(1, $this->executor->callCount(), 'After Resume the due work runs.');
    }

    /** 17. Resume never wakes a wait that is not due yet. */
    public function test_resume_does_not_prematurely_wake_a_future_wait(): void
    {
        [$workflow, $enrollment] = $this->waitingJourney(120);
        $before = $this->storedResumeAt($enrollment);

        app(WorkflowLifecycle::class)->pause($workflow);

        Bus::fake([AdvanceWorkflowEnrollment::class, RedispatchHeldEnrollments::class]);

        app(WorkflowLifecycle::class)->resume($workflow->fresh());
        (new RedispatchHeldEnrollments((int) $workflow->id))->handle();

        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->status, 'Still waiting.');
        $this->assertEquals($before, $this->storedResumeAt($enrollment), 'And still waiting until the same instant.');
        $this->assertSame(0, $this->executor->callCount());
    }

    /** 18. A cancelled or archived journey never wakes, however due it looks. */
    public function test_a_cancelled_or_archived_journey_never_wakes(): void
    {
        [$workflow, $enrollment] = $this->waitingJourney();
        $this->makeWaitDue($enrollment);

        // Archiving cancels journeys in flight.
        app(WorkflowLifecycle::class)->archive($workflow);

        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->fresh()->status);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->wake()->wakeDue();

        $this->assertSame(0, $counts['woken']);
        $this->assertSame(0, $counts['examined'], 'A cancelled journey is not even a candidate.');
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);
        $this->assertSame(0, $this->executor->callCount());
    }

    /** 18b. A terminal journey carrying a stale resume_at is likewise ignored. */
    public function test_a_completed_journey_with_a_stale_wake_time_is_ignored(): void
    {
        [, $enrollment] = $this->waitingJourney();

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'status' => EnrollmentStatus::Completed->value,
            'resume_at' => Carbon::now()->subHour(),
        ]);

        $counts = $this->wake()->wakeDue();

        $this->assertSame(0, $counts['examined']);
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    /** 19 + 20. The per-run cap bounds one sweep; the next continues the rest. */
    public function test_the_sweep_is_capped_and_the_next_run_continues(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [
            $this->waitStep(30, 'minutes'),
            $this->recordedStep('after'),
            $this->endStep(),
        ]);

        $enrollments = [];

        for ($i = 0; $i < 5; $i++) {
            $contact = $this->contactFor($business, 'Waiter' . $i);
            $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
            app(WorkflowAdvancer::class)->advance($enrollment);
            $this->makeWaitDue($enrollment->fresh());
            $enrollments[] = $enrollment;
        }

        $this->assertSame(5, AutomationEnrollment::query()
            ->where('status', EnrollmentStatus::Waiting->value)->count());

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        // Driven with a small cap so the real bounding mechanism is exercised
        // without manufacturing SWEEP_ENROLLMENTS_PER_RUN rows.
        $first = $this->wake()->wakeDue(null, 2);

        $this->assertSame(2, $first['woken'], 'The cap stops the run.');
        $this->assertSame(3, AutomationEnrollment::query()
            ->where('status', EnrollmentStatus::Waiting->value)->count());

        $second = $this->wake()->wakeDue(null, 2);
        $this->assertSame(2, $second['woken'], 'The next run continues where the last stopped.');

        $third = $this->wake()->wakeDue(null, 2);
        $this->assertSame(1, $third['woken']);

        $this->assertSame(0, AutomationEnrollment::query()
            ->where('status', EnrollmentStatus::Waiting->value)->count(), 'All five eventually wake.');
    }

    /** The command runs the sweep and reports it. */
    public function test_the_command_runs_the_sweep(): void
    {
        [, $enrollment] = $this->waitingJourney();
        $this->makeWaitDue($enrollment);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $this->artisan('automation:workflows-resume-due')
            ->expectsOutputToContain('1 woken')
            ->assertSuccessful();
    }

    /**
     * The wake sweep and the recovery sweep cannot double-process a journey,
     * because they select disjoint statuses. This is the guarantee that let the
     * two commands stay separate.
     */
    public function test_the_wake_and_recovery_sweeps_never_see_the_same_row(): void
    {
        [, $enrollment] = $this->waitingJourney();

        // Old enough that recovery would grab it if status alone did not exclude it.
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'resume_at' => Carbon::now()->subHour(),
            'last_advanced_at' => Carbon::now()->subDay(),
            'enrolled_at' => Carbon::now()->subDay(),
        ]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $recovered = app(WorkflowRecoveryService::class)->recoverStalled();

        $this->assertSame(
            ['redispatched' => 0, 'failed' => 0, 'expired' => 0],
            $recovered,
            'Recovery must not touch a waiting journey — that is the wake sweep\'s row.',
        );
        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->fresh()->status);

        $woken = $this->wake()->wakeDue();

        $this->assertSame(1, $woken['woken'], 'And the wake sweep does take it.');
    }
}
