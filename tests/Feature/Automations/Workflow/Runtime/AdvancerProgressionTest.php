<?php

namespace Tests\Feature\Automations\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 runtime — progression, completion, at-most-once and failure.
 *
 * These are the properties a customer feels: a journey moves forward once per
 * step, finishes when it runs out of steps, and never sends anything twice
 * however many times the queue delivers the same job.
 */
class AdvancerProgressionTest extends TestCase
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

    private function advancer(): WorkflowAdvancer
    {
        return app(WorkflowAdvancer::class);
    }

    private function enroll(array $steps): AutomationEnrollment
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, $steps);
        $contact = $this->contactFor($business);

        return app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
    }

    /** A journey runs its steps in order and finishes at the end of the path. */
    public function test_a_journey_runs_every_step_in_order_and_then_completes(): void
    {
        $enrollment = $this->enroll([
            $this->recordedStep('one'),
            $this->recordedStep('two'),
            $this->endStep(),
        ]);

        $this->advancer()->advance($enrollment);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertNull($enrollment->current_node_id, 'A finished journey holds no cursor.');
        $this->assertNotNull($enrollment->completed_at);

        // Four steps ran: trigger, two recorded steps, end.
        $runs = DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->get();
        $this->assertCount(4, $runs);
        $this->assertTrue($runs->every(fn ($r): bool => $r->status === StepRunStatus::Succeeded->value));
        $this->assertSame(2, $this->executor->callCount(), 'Only the two recorded steps use the executor.');
    }

    /** A path with no explicit End completes the same way. */
    public function test_a_path_that_simply_runs_out_completes_deterministically(): void
    {
        $enrollment = $this->enroll([$this->recordedStep('only')]);

        $this->advancer()->advance($enrollment);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(
            2,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
        );
    }

    /**
     * The at-most-once guarantee. However many times the same job is delivered,
     * a node executes once.
     */
    public function test_duplicate_dispatch_cannot_execute_a_node_twice(): void
    {
        $enrollment = $this->enroll([$this->recordedStep('once'), $this->endStep()]);

        // Five deliveries of the same work.
        for ($i = 0; $i < 5; $i++) {
            $this->advancer()->advance($enrollment->fresh() ?? $enrollment);
        }

        $this->assertSame(1, $this->executor->callCount(), 'The step must execute exactly once.');
        $this->assertSame(
            3,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
            'One step run per node, and no more.',
        );
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    /** The same, through the real job, which is how production delivers it. */
    public function test_the_job_is_idempotent_when_retried(): void
    {
        $enrollment = $this->enroll([$this->recordedStep('job'), $this->endStep()]);

        (new AdvanceWorkflowEnrollment((int) $enrollment->id))->handle($this->advancer());
        (new AdvanceWorkflowEnrollment((int) $enrollment->id))->handle($this->advancer());
        (new AdvanceWorkflowEnrollment((int) $enrollment->id))->handle($this->advancer());

        $this->assertSame(1, $this->executor->callCount());
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    /** A step that fails halts the journey, durably, under the pinned policy. */
    public function test_a_failed_step_halts_the_journey_and_the_state_is_durable(): void
    {
        $enrollment = $this->enroll([
            $this->recordedStep('will fail'),
            $this->recordedStep('never runs'),
            $this->endStep(),
        ]);

        $this->executor->nextOutcome = NodeExecutionOutcome::failed('provider_unavailable');

        $this->advancer()->advance($enrollment);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
        $this->assertSame('provider_unavailable', $enrollment->exit_reason);
        $this->assertNull($enrollment->current_node_id);
        $this->assertSame(1, $this->executor->callCount(), 'Nothing after the failure may run.');

        $failed = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)
            ->where('status', StepRunStatus::Failed->value)
            ->first();

        $this->assertNotNull($failed);
        $this->assertSame('provider_unavailable', $failed->safe_error_summary);

        // Durable: re-running the job changes nothing.
        (new AdvanceWorkflowEnrollment((int) $enrollment->id))->handle($this->advancer());

        $this->assertSame(EnrollmentStatus::Failed, $enrollment->fresh()->status);
        $this->assertSame(1, $this->executor->callCount());
    }

    /** A skipped step is stepped over, not treated as the end of the journey. */
    public function test_a_skipped_step_does_not_end_the_journey(): void
    {
        $enrollment = $this->enroll([
            $this->recordedStep('skipped'),
            $this->recordedStep('still runs'),
            $this->endStep(),
        ]);

        $this->executor->nextOutcome = NodeExecutionOutcome::skipped('contact_unsubscribed');

        $this->advancer()->advance($enrollment);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(2, $this->executor->callCount(), 'The step after a skip must still run.');

        $skipped = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)
            ->where('status', StepRunStatus::Skipped->value)
            ->count();

        $this->assertSame(1, $skipped);
    }

    /**
     * A step type with no executor yet holds the journey instead of dropping the
     * step or ending the journey over a gap that is temporary by design.
     */
    public function test_a_step_with_no_executor_holds_the_journey_untouched(): void
    {
        [, $business] = $this->entitledTenant();
        // `update_contact_field` has no executor in this slice.
        [$workflow] = $this->publishWorkflow($business, [
            ['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'wait',
             'config' => ['mode' => 'duration', 'amount' => 1, 'unit' => 'hours']],
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        $this->advancer()->advance($enrollment);

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status, 'The journey must be held, not ended.');
        $this->assertNotNull($enrollment->current_node_id);

        $waitNodeId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $enrollment->version_id)->where('node_type', 'wait')->value('id');

        $this->assertSame($waitNodeId, (int) $enrollment->current_node_id, 'It waits at the step it cannot run.');
        $this->assertSame(
            0,
            DB::table('automation_step_runs')->where('node_id', $waitNodeId)->count(),
            'No step run may be written for a step that was never claimed.',
        );
    }

    /** Only the pinned version's graph is executed, never a newer one. */
    public function test_a_republish_does_not_change_a_running_journey(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow, $versionOne] = $this->publishWorkflow($business, [
            $this->recordedStep('v1 step'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        // Republish with a longer body BEFORE the journey runs.
        $drafts = app(WorkflowDraftService::class);
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $definition['root']['next'] = [
            $this->recordedStep('v2 step a'),
            $this->recordedStep('v2 step b'),
            $this->recordedStep('v2 step c'),
            $this->endStep(),
        ];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $this->advancer()->advance($enrollment->fresh());

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(
            1,
            $this->executor->callCount(),
            'The journey must run version one\'s single step, not version two\'s three.',
        );

        // Every step it ran belongs to the version it started on.
        $versionIds = DB::table('automation_step_runs')
            ->join('automation_workflow_nodes', 'automation_workflow_nodes.id', '=', 'automation_step_runs.node_id')
            ->where('automation_step_runs.enrollment_id', $enrollment->id)
            ->pluck('automation_workflow_nodes.version_id')->unique()->all();

        $this->assertSame([(int) $versionOne->id], array_map('intval', $versionIds));
    }

    /** Tenancy stays fail-closed at execution, not only at enrollment. */
    public function test_a_contact_that_leaves_the_business_ends_the_journey(): void
    {
        $enrollment = $this->enroll([$this->recordedStep('a'), $this->endStep()]);

        DB::table('contacts')->where('id', $enrollment->contact_id)->update(['business_id' => null]);

        $this->advancer()->advance($enrollment->fresh());

        $enrollment = $enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Exited, $enrollment->status);
        $this->assertSame('contact_not_in_business', $enrollment->exit_reason);
        $this->assertSame(0, $this->executor->callCount(), 'Nothing may execute for a contact outside the Business.');
    }

    /** One job runs a bounded number of steps and asks to continue. */
    public function test_a_long_journey_is_continued_across_jobs(): void
    {
        $steps = [];

        for ($i = 0; $i < 14; $i++) {
            $steps[] = $this->recordedStep('step ' . $i);
        }

        $steps[] = $this->endStep();

        $enrollment = $this->enroll($steps);

        $wantsMore = $this->advancer()->advance($enrollment);

        $this->assertTrue($wantsMore, 'A journey longer than one job\'s budget must ask to be continued.');
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);

        // Continue until it finishes.
        while ($this->advancer()->advance($enrollment->fresh())) {
            // The loop is the point: each job does a bounded amount of work.
        }

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(14, $this->executor->callCount());
    }
}
