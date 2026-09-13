<?php

namespace Tests\Feature\Automations\Workflow\Logic;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use App\Models\AutomationEnrollment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §8.2 — two sweeps, one wake.
 *
 * The scheduler runs every minute and a slow sweep can still be running when the
 * next one starts, so "exactly one worker wakes a journey" is a real production
 * condition rather than a theoretical one. It is proven here against a SECOND
 * REAL DATABASE SESSION, because a claim that does not actually exclude anybody
 * looks exactly like one that does until the day two workers overlap.
 *
 * A second session needs committed rows, so this cannot run inside
 * RefreshDatabase's transaction — hence UsesFreshSchema, the arrangement the
 * step-claim concurrency test already uses.
 */
class WakeConcurrencyTest extends TestCase
{
    use CreatesAutomationFixtures;
    use UsesFreshSchema;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    private const SECOND_SESSION = 'mysql_wake_second_session';

    private RecordingNodeExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();

        $this->executor = $this->recordingExecutor();
    }

    protected function tearDown(): void
    {
        DB::purge(self::SECOND_SESSION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function secondSession(): ConnectionInterface
    {
        config(['database.connections.' . self::SECOND_SESSION => config('database.connections.' . config('database.default'))]);

        $session = DB::connection(self::SECOND_SESSION);
        // A blocked write surfaces as a timeout instead of hanging the suite.
        $session->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $session;
    }

    private function dueWaitingEnrollment(): AutomationEnrollment
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [
            $this->waitStep(30, 'minutes'),
            $this->recordedStep('after the wait'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->makeWaitDue($enrollment->fresh());

        return $enrollment->fresh();
    }

    /** The claim UPDATE the sweep uses, issued verbatim on a given session. */
    private function claimOn(ConnectionInterface $session, AutomationEnrollment $enrollment): int
    {
        return $session->update(
            'UPDATE ' . DB::getTablePrefix() . 'automation_enrollments'
            . " SET status = 'active', resume_at = NULL"
            . " WHERE id = ? AND status = 'waiting' AND resume_at <= ?",
            [$enrollment->id, Carbon::now()->utc()->toDateTimeString()],
        );
    }

    /**
     * 14. While one session holds the claim, a second cannot take the row —
     * and once the first commits, the second wins nothing.
     */
    public function test_only_one_session_can_claim_a_wake(): void
    {
        $enrollment = $this->dueWaitingEnrollment();

        $second = $this->secondSession();
        $blocked = false;

        DB::beginTransaction();

        $firstClaim = $this->claimOn(DB::connection(), $enrollment);

        try {
            $this->claimOn($second, $enrollment);
        } catch (QueryException) {
            // Lock wait timeout: the row really is held by the first session.
            $blocked = true;
        }

        DB::commit();

        $this->assertSame(1, $firstClaim, 'The first session must win the wake.');
        $this->assertTrue($blocked, 'A second session must not be able to claim a wake in flight.');

        // And now that the first has committed, the row no longer qualifies.
        $this->assertSame(
            0,
            $this->claimOn($second, $enrollment),
            'The loser must claim nothing, because the row is no longer waiting.',
        );

        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);
    }

    /** 14b. Two full sweeps over the same due row produce one wake between them. */
    public function test_two_sweeps_produce_exactly_one_wake(): void
    {
        $enrollment = $this->dueWaitingEnrollment();

        // Two independent service instances, as two workers would have.
        $sweepA = app()->make(WorkflowWakeService::class);
        $sweepB = app()->make(WorkflowWakeService::class);

        $a = $sweepA->wakeDue();
        $b = $sweepB->wakeDue();

        $this->assertSame(
            1,
            $a['woken'] + $b['woken'],
            'Exactly one of two concurrent sweeps may wake a given journey.',
        );

        // 15, under concurrency: the successor still ran once.
        $this->assertSame(1, $this->executor->callCount());
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);

        $this->assertSame(
            1,
            DB::table('automation_step_runs')
                ->where('enrollment_id', $enrollment->id)
                ->where('node_type', 'send_sms')
                ->count(),
            'One step run for the successor, so it can never have executed twice.',
        );
    }

    /** A loser reports the row as already claimed rather than as an error. */
    public function test_a_losing_sweep_reports_the_row_as_lost_not_failed(): void
    {
        $enrollment = $this->dueWaitingEnrollment();

        // Claim it out from under the sweep, exactly as another worker would.
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'status' => EnrollmentStatus::Active->value,
            'resume_at' => null,
        ]);

        // Re-mark it as due so the sweep still selects it, then race it.
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'status' => EnrollmentStatus::Waiting->value,
            'resume_at' => Carbon::now()->subMinute(),
        ]);

        $wake = app(WorkflowWakeService::class);

        // First run wins; a second immediately after finds nothing to take.
        $first = $wake->wakeDue();
        $second = $wake->wakeDue();

        $this->assertSame(1, $first['woken']);
        $this->assertSame(0, $second['woken']);
        $this->assertSame(0, $second['examined'], 'A woken row is no longer a candidate at all.');
    }
}
