<?php

namespace Tests\Feature\Automations\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §7.4 — the lost-and-interrupted-work safety net.
 *
 * Two situations look identical from the outside — an `active` journey that has
 * not moved for a quarter of an hour — and must be handled completely
 * differently. The cursor's step run is what tells them apart, and the node's
 * side-effect class decides what may be done about it. Getting that wrong means
 * either a stuck journey or a text message sent twice.
 */
class RecoveryTest extends TestCase
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

    private function recovery(): WorkflowRecoveryService
    {
        return app(WorkflowRecoveryService::class);
    }

    private function stalledEnrollment(): AutomationEnrollment
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('a'), $this->endStep()]);
        $contact = $this->contactFor($business);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        $this->makeStale($enrollment);

        return $enrollment->fresh();
    }

    private function makeStale(AutomationEnrollment $enrollment): void
    {
        $stale = Carbon::now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5);

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'last_advanced_at' => $stale,
            'enrolled_at' => $stale,
        ]);
    }

    /** A journey that has not moved recently enough is left alone. */
    public function test_a_recently_active_journey_is_not_touched(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('a'), $this->endStep()]);
        $contact = $this->contactFor($business);
        app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(0, $counts['redispatched'], 'Recovery must only look at genuinely stalled work.');
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);
    }

    /** A lost job: nothing ran, so simply ask again. */
    public function test_a_stalled_journey_with_no_step_run_is_redispatched(): void
    {
        $enrollment = $this->stalledEnrollment();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(1, $counts['redispatched']);
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class);
        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->fresh()->status,
            'A lost job changes nothing about the journey itself.',
        );
    }

    /**
     * An interrupted PURE step is safe to re-derive: the claim is reopened so the
     * advancer can run it again from a clean state.
     */
    public function test_an_interrupted_pure_step_is_reopened_and_redispatched(): void
    {
        $enrollment = $this->stalledEnrollment();

        // The cursor is the trigger node — side-effect class None.
        $nodeId = (int) $enrollment->current_node_id;
        $this->insertStartedStepRun($enrollment, $nodeId, 'trigger');

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(1, $counts['redispatched']);
        $this->assertSame(
            0,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
            'A pure step\'s abandoned claim is released so it can run again.',
        );
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);
    }

    /**
     * An interrupted EXTERNAL step is never re-run. Its outcome is unknown, and
     * B4 §5.1 rule 4 accepts losing the action rather than risking a duplicate.
     */
    public function test_an_interrupted_external_step_is_failed_and_never_retried(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('external'), $this->endStep()]);
        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        // Move the cursor onto the external step and abandon a claim there.
        $externalNodeId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $enrollment->version_id)
            ->where('node_type', 'send_sms')
            ->value('id');

        DB::table('automation_enrollments')->where('id', $enrollment->id)
            ->update(['current_node_id' => $externalNodeId]);

        $this->insertStartedStepRun($enrollment, $externalNodeId, 'send_sms');
        $this->makeStale($enrollment);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['redispatched'], 'An external step must never be re-dispatched.');
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);

        $stepRun = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)->where('node_id', $externalNodeId)->first();

        $this->assertSame(StepRunStatus::Failed->value, $stepRun->status);
        $this->assertSame('interrupted_outcome_unknown', $stepRun->safe_error_summary);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
        $this->assertSame('interrupted_outcome_unknown', $enrollment->exit_reason);
        $this->assertSame(0, $this->executor->callCount(), 'The action must not run a second time.');
    }

    /** A step that finished but never advanced is simply picked up again. */
    public function test_a_finished_step_whose_cursor_never_moved_is_redispatched(): void
    {
        $enrollment = $this->stalledEnrollment();

        DB::table('automation_step_runs')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $enrollment->current_node_id,
            'node_type' => 'trigger',
            'status' => StepRunStatus::Succeeded->value,
            'started_at' => Carbon::now()->subHour(),
            'completed_at' => Carbon::now()->subHour(),
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(1, $counts['redispatched']);
        $this->assertSame(
            1,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
            'A completed step run must be left exactly as it is.',
        );
    }

    /** A journey older than the lifetime cap is closed rather than resurrected. */
    public function test_a_journey_past_its_lifetime_is_exited(): void
    {
        $enrollment = $this->stalledEnrollment();

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'enrolled_at' => Carbon::now()->subDays(WorkflowLimits::MAX_ENROLLMENT_LIFETIME_DAYS + 10),
            'last_advanced_at' => Carbon::now()->subDays(WorkflowLimits::MAX_ENROLLMENT_LIFETIME_DAYS + 10),
        ]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(1, $counts['expired']);
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Exited, $enrollment->status);
        $this->assertSame('lifetime_exceeded', $enrollment->exit_reason);
        $this->assertNull($enrollment->current_node_id);
    }

    /** The console command runs the sweep and reports what it did. */
    public function test_the_command_runs_the_sweep(): void
    {
        $this->stalledEnrollment();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $this->artisan('automation:workflows-recover-stalled')
            ->expectsOutputToContain('1 re-dispatched')
            ->assertSuccessful();
    }

    /** Recovery never resurrects a journey that already ended. */
    public function test_a_terminal_journey_is_never_recovered(): void
    {
        $enrollment = $this->stalledEnrollment();

        app(WorkflowAdvancer::class)->finish($enrollment, EnrollmentStatus::Completed, null);
        $this->makeStale($enrollment);

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $counts = $this->recovery()->recoverStalled();

        $this->assertSame(['redispatched' => 0, 'failed' => 0, 'expired' => 0], $counts);
        Bus::assertNotDispatched(AdvanceWorkflowEnrollment::class);
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    private function insertStartedStepRun(AutomationEnrollment $enrollment, int $nodeId, string $type): void
    {
        DB::table('automation_step_runs')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $nodeId,
            'node_type' => $type,
            'status' => StepRunStatus::Started->value,
            'started_at' => Carbon::now()->subHour(),
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);
    }
}
