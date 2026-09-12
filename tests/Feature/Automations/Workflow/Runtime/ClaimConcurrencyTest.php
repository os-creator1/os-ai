<?php

namespace Tests\Feature\Automations\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowStepClaimService;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §7.3 — two workers cannot own the same step.
 *
 * This is proven against a REAL second database session rather than by reasoning
 * about the code, because a lock that does not actually exclude anybody looks
 * exactly like one that does until the day it matters. There are no sleeps here:
 * the claim service exposes a seam that fires while it holds its locks, and the
 * second session acts at precisely that moment, with a one-second lock-wait
 * timeout so a genuine block is observable as a timeout rather than a hang.
 *
 * A second session needs committed fixture rows, so this test cannot run inside
 * RefreshDatabase's transaction — hence UsesFreshSchema, the same arrangement
 * B4's own claim-concurrency test uses.
 */
class ClaimConcurrencyTest extends TestCase
{
    use CreatesAutomationFixtures;
    use UsesFreshSchema;
    use BuildsWorkflows;

    private const SECOND_SESSION = 'mysql_v2_second_session';

    private RecordingNodeExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();

        $this->executor = $this->recordingExecutor();
    }

    protected function tearDown(): void
    {
        WorkflowStepClaimService::$afterClaimLocks = null;
        DB::purge(self::SECOND_SESSION);

        parent::tearDown();
    }

    private function secondSession(): ConnectionInterface
    {
        config(['database.connections.' . self::SECOND_SESSION => config('database.connections.' . config('database.default'))]);

        $session = DB::connection(self::SECOND_SESSION);
        // A blocked write should surface quickly as a timeout instead of hanging
        // the test run.
        $session->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $session;
    }

    /** @return array{0: AutomationEnrollment, 1: AutomationWorkflowNode} */
    private function enrollmentAtFirstStep(): array
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('contested'), $this->endStep()]);
        $contact = $this->contactFor($business);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        $node = AutomationWorkflowNode::query()->findOrFail($enrollment->current_node_id);

        return [$enrollment, $node];
    }

    /**
     * While one worker holds the claim locks, a second session cannot modify the
     * same enrollment row. That exclusion is what makes the claim a claim.
     */
    public function test_a_second_session_is_blocked_from_the_enrollment_while_a_claim_is_held(): void
    {
        [$enrollment, $node] = $this->enrollmentAtFirstStep();

        $second = $this->secondSession();
        $blocked = false;

        WorkflowStepClaimService::$afterClaimLocks = function () use ($second, $enrollment, &$blocked): void {
            try {
                $second->update(
                    'UPDATE ' . DB::getTablePrefix() . 'automation_enrollments SET step_count = step_count + 1 WHERE id = ?',
                    [$enrollment->id],
                );
            } catch (QueryException) {
                // Lock wait timeout: the row really is held.
                $blocked = true;
            }
        };

        $stepRun = app(WorkflowStepClaimService::class)->claim($enrollment, $node);

        $this->assertNotNull($stepRun, 'The first worker must win the claim.');
        $this->assertTrue($blocked, 'A second session must not be able to touch a claimed enrollment.');

        // And once the claim has committed, the row is writable again. The write
        // has to change something real: MySQL reports rows CHANGED, so a no-op
        // update would report zero and prove nothing either way.
        $this->assertSame(
            1,
            $second->update(
                'UPDATE ' . DB::getTablePrefix() . 'automation_enrollments SET step_count = step_count + 1 WHERE id = ?',
                [$enrollment->id],
            ),
            'The lock must be released once the claim transaction commits.',
        );
    }

    /**
     * Two claims for the same step: exactly one wins, and the loser is told so by
     * getting null rather than by an exception it has to interpret.
     */
    public function test_only_one_of_two_claims_for_the_same_step_can_win(): void
    {
        [$enrollment, $node] = $this->enrollmentAtFirstStep();

        $claims = app(WorkflowStepClaimService::class);

        $first = $claims->claim($enrollment, $node);
        $second = $claims->claim($enrollment->fresh(), $node);

        $this->assertNotNull($first);
        $this->assertNull($second, 'The second claim for a step already claimed must lose.');
        $this->assertSame(
            1,
            DB::table('automation_step_runs')
                ->where('enrollment_id', $enrollment->id)->where('node_id', $node->id)->count(),
        );
    }

    /**
     * A worker whose enrollment has moved on since its job was queued must not
     * run the old step — the cursor check catches it before anything happens.
     */
    public function test_a_claim_against_a_moved_cursor_is_refused(): void
    {
        [$enrollment, $node] = $this->enrollmentAtFirstStep();

        // Somebody else advanced the journey.
        $otherNodeId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $enrollment->version_id)
            ->where('node_type', 'send_sms')
            ->value('id');

        DB::table('automation_enrollments')->where('id', $enrollment->id)
            ->update(['current_node_id' => $otherNodeId]);

        $this->assertNull(
            app(WorkflowStepClaimService::class)->claim($enrollment->fresh(), $node),
            'A stale cursor must not be able to claim its old step.',
        );
        $this->assertSame(0, DB::table('automation_step_runs')->count());
    }

    /** A terminal journey cannot be claimed at all. */
    public function test_a_terminal_enrollment_cannot_be_claimed(): void
    {
        [$enrollment, $node] = $this->enrollmentAtFirstStep();

        app(WorkflowAdvancer::class)->finish($enrollment, EnrollmentStatus::Cancelled, 'stopped_by_user');

        $this->assertNull(app(WorkflowStepClaimService::class)->claim($enrollment->fresh(), $node));
        $this->assertSame(0, DB::table('automation_step_runs')->count());
    }

    /**
     * The end-to-end property the whole design exists for: many deliveries of the
     * same work, one execution.
     */
    public function test_many_concurrent_advances_execute_each_step_exactly_once(): void
    {
        [$enrollment] = $this->enrollmentAtFirstStep();

        $advancer = app(WorkflowAdvancer::class);

        for ($i = 0; $i < 8; $i++) {
            $advancer->advance($enrollment->fresh() ?? $enrollment);
        }

        $this->assertSame(1, $this->executor->callCount(), 'The action must have run exactly once.');
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);

        $perNode = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)
            ->selectRaw('node_id, COUNT(*) as runs')
            ->groupBy('node_id')
            ->pluck('runs')
            ->all();

        $this->assertSame(
            [1, 1, 1],
            array_map('intval', $perNode),
            'Every node must hold exactly one step run.',
        );
    }
}
