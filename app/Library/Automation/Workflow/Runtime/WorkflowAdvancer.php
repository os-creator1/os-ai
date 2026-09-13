<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflowEdge;
use App\Models\AutomationWorkflowNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automations V2 §7.3 — running one enrollment forward.
 *
 * The order of operations is the whole design, and each step of it exists because
 * of a specific way journeys go wrong:
 *
 *   1. CHECK, without locks. A paused workflow or a revoked entitlement stops
 *      here, WITHOUT claiming, so the enrollment stays exactly where it is and
 *      Resume can continue it. A missing contact ends it honestly instead.
 *   2. CLAIM, in a short lock-held transaction with no I/O (see
 *      WorkflowStepClaimService). Losing the claim means another worker owns this
 *      step; this one returns having done nothing.
 *   3. RE-CHECK after the claim. Eligibility can change between 1 and 2, and the
 *      executor must act on objects read after the claim, never before it.
 *   4. EXECUTE outside every transaction. No lock is held across a provider call,
 *      ever.
 *   5. RECORD AND ADVANCE, with an EXPECTED-CURSOR predicate. The update moves
 *      the cursor only if it is still where we left it; zero affected rows means
 *      somebody else moved it and this worker stops. That predicate is deliberate:
 *      the Slice 3 adversarial review found exactly this lost-update shape in the
 *      managed delivery-status path, where a plain `update by id` let two workers
 *      overwrite each other.
 *
 * The loop runs at most MAX_STEPS_PER_ADVANCE_JOB steps and then asks to be
 * re-dispatched, so one job stays well inside the worker's timeout.
 */
class WorkflowAdvancer
{
    public function __construct(
        private readonly WorkflowCheckpoint $checkpoint,
        private readonly WorkflowStepClaimService $claims,
        private readonly NodeExecutorRegistry $executors,
    ) {
    }

    /**
     * Advance one enrollment as far as it can go right now.
     *
     * @return bool whether the caller should re-dispatch to continue. False means
     *              the journey is finished, held, or handed to another worker.
     */
    public function advance(AutomationEnrollment $enrollment): bool
    {
        for ($step = 0; $step < WorkflowLimits::MAX_STEPS_PER_ADVANCE_JOB; $step++) {
            $enrollment = $enrollment->fresh();

            if ($enrollment === null || $enrollment->isTerminal() || ! $enrollment->isExecutableNow()) {
                return false;
            }

            if ($enrollment->step_count >= WorkflowLimits::MAX_NODES_PER_VERSION) {
                // Defence in depth. The tree shape already makes an endless
                // traversal impossible; if this ever fires, something is wrong
                // enough that continuing would be worse than stopping.
                $this->finish($enrollment, EnrollmentStatus::Exited, 'step_limit_exceeded');

                return false;
            }

            // (1)
            $checkpoint = $this->checkpoint->resolve($enrollment);

            if (! $checkpoint->ok) {
                if ($checkpoint->shouldExit) {
                    $this->finish($enrollment, EnrollmentStatus::Exited, (string) $checkpoint->reason);
                }

                // Held: nothing is written, nothing is claimed, the cursor stays.
                return false;
            }

            $node = AutomationWorkflowNode::query()->find($enrollment->current_node_id);

            if ($node === null) {
                $this->finish($enrollment, EnrollmentStatus::Exited, 'node_missing');

                return false;
            }

            $executor = $this->executors->for($node->node_type);

            if ($executor === null) {
                // A step type whose executor has not shipped yet. Refusing to
                // claim leaves the journey intact so it simply continues once the
                // executor exists — skipping would drop a step out of a customer's
                // workflow, and failing would end it over a temporary gap.
                return false;
            }

            // (2)
            $stepRun = $this->claims->claim($enrollment, $node);

            if ($stepRun === null) {
                return false;
            }

            // (3)
            $recheck = $this->checkpoint->resolve($enrollment->fresh());

            if (! $recheck->ok) {
                $this->closeStep($stepRun, StepRunStatus::Skipped, null, (string) $recheck->reason);

                if ($recheck->shouldExit) {
                    $this->finish($enrollment->fresh(), EnrollmentStatus::Exited, (string) $recheck->reason);
                } else {
                    // Held after the claim. The step is spent — it is never retried
                    // (B4 §5.1) — so the journey moves past it rather than stalling
                    // on a step that can no longer run.
                    $this->moveOn($enrollment->fresh(), $node, null);
                }

                return false;
            }

            // (4) — outside every transaction, holding no lock.
            // Every object handed to the executor was read AFTER the claim.
            $outcome = $executor->execute(
                $node,
                $enrollment->fresh(),
                $recheck->business,
                $recheck->contact,
            );

            // (5)
            $continue = $this->recordAndAdvance($enrollment->fresh(), $node, $stepRun, $outcome, $recheck);

            if (! $continue) {
                return false;
            }
        }

        // Budget spent with more to do.
        return true;
    }

    /**
     * Write the step's result and move the cursor, or close the journey.
     *
     * @return bool whether there is another step to run immediately
     */
    private function recordAndAdvance(
        ?AutomationEnrollment $enrollment,
        AutomationWorkflowNode $node,
        AutomationStepRun $stepRun,
        NodeExecutionOutcome $outcome,
        CheckpointResult $checkpoint,
    ): bool {
        if ($enrollment === null) {
            return false;
        }

        // A WAIT ARRIVES ATOMICALLY (§12). The step run becoming `waiting` and
        // the enrollment becoming `waiting` with its `resume_at` are one fact,
        // so they are one transaction. Split across two statements there is a
        // window in which the step says "waiting" while the enrollment is still
        // `active` — long enough for the recovery sweep to read it as a stalled
        // active journey, or for a second advance to claim past it. Neither can
        // observe a half-parked journey now, and no second `resume_at` can be
        // written because the enrollment update is conditional on the cursor and
        // status it was read at.
        if ($outcome->status === StepRunStatus::Waiting && $outcome->resumeAt !== null) {
            return DB::transaction(function () use ($enrollment, $node, $stepRun, $outcome): bool {
                $this->closeStep(
                    $stepRun,
                    $outcome->status,
                    $outcome->branchTaken,
                    $outcome->safeErrorSummary,
                    $outcome->safeResultSummary,
                );

                $this->park($enrollment, $node, $outcome->resumeAt);

                return false;
            });
        }

        $this->closeStep(
            $stepRun,
            $outcome->status,
            $outcome->branchTaken,
            $outcome->safeErrorSummary,
            $outcome->safeResultSummary,
        );

        if ($outcome->status === StepRunStatus::Failed) {
            // Failure policy is versioned and pinned, so a journey fails the way
            // the version it started on says it should — not the way the current
            // draft says (§7.6).
            $policy = $checkpoint->version?->failure_policy;

            if ($policy === null || $policy->haltsEnrollment()) {
                $this->finish($enrollment, EnrollmentStatus::Failed, $outcome->safeErrorSummary ?? 'step_failed');

                return false;
            }
        }

        if ($outcome->status === StepRunStatus::Skipped) {
            // Skipped means "could not run", not "the journey is over": move past
            // it. A skip that should end the journey exits through the checkpoint
            // instead, which is the only place that decision belongs.
            return $this->moveOn($enrollment, $node, null);
        }

        return $this->moveOn($enrollment, $node, $outcome->branchTaken);
    }

    /**
     * Move the cursor to the successor, or complete. The update is conditional on
     * the cursor not having moved.
     */
    private function moveOn(
        ?AutomationEnrollment $enrollment,
        AutomationWorkflowNode $node,
        ?WorkflowEdgeKind $branch,
    ): bool {
        if ($enrollment === null) {
            return false;
        }

        $successorId = $this->successorId($node, $branch);

        if ($successorId === null) {
            // No successor: the path is finished. This is how BOTH an explicit End
            // step and the last step of any lane complete — one rule, one place.
            $this->finish($enrollment, EnrollmentStatus::Completed, null);

            return false;
        }

        $moved = AutomationEnrollment::query()
            ->whereKey($enrollment->getKey())
            ->where('current_node_id', $node->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->update([
                'current_node_id' => $successorId,
                'step_count' => DB::raw('step_count + 1'),
                'last_advanced_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        // Zero rows: another worker advanced, stopped or cancelled this journey
        // while we were executing. Stop rather than overwrite its decision.
        return $moved === 1;
    }

    /** The edge out of `$node` for the branch taken, if any. */
    private function successorId(AutomationWorkflowNode $node, ?WorkflowEdgeKind $branch): ?int
    {
        $kind = $branch ?? WorkflowEdgeKind::Next;

        $edge = AutomationWorkflowEdge::query()
            ->where('from_node_id', $node->getKey())
            ->where('edge_kind', $kind->value)
            ->first();

        return $edge === null ? null : (int) $edge->to_node_id;
    }

    /**
     * Park the journey on the wait node until `$resumeAt`.
     *
     * Conditional on the cursor as well as the status, for the same reason
     * moveOn() is: if another worker has already advanced or ended this journey
     * while this one was evaluating, its decision stands and this update affects
     * nothing rather than overwriting a newer `resume_at` with a stale one.
     */
    private function park(AutomationEnrollment $enrollment, AutomationWorkflowNode $node, Carbon $resumeAt): void
    {
        AutomationEnrollment::query()
            ->whereKey($enrollment->getKey())
            ->where('current_node_id', $node->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->update([
                'status' => EnrollmentStatus::Waiting->value,
                'resume_at' => $resumeAt,
                'last_advanced_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * The node a given branch leaves this node for, or null when the path ends.
     *
     * Public because the wake sweep (WorkflowWakeService) must move a woken
     * journey to the wait node's successor, and duplicating the edge lookup
     * there would mean two places deciding what "the next step" is.
     */
    public function successorOf(AutomationWorkflowNode $node, ?WorkflowEdgeKind $branch = null): ?int
    {
        return $this->successorId($node, $branch);
    }

    /** Close a journey durably, clearing the cursor so nothing can resume it. */
    public function finish(?AutomationEnrollment $enrollment, EnrollmentStatus $status, ?string $reason): void
    {
        if ($enrollment === null) {
            return;
        }

        AutomationEnrollment::query()
            ->whereKey($enrollment->getKey())
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Waiting->value])
            ->update([
                'status' => $status->value,
                'current_node_id' => null,
                'resume_at' => null,
                'completed_at' => Carbon::now(),
                'last_advanced_at' => Carbon::now(),
                'exit_reason' => $reason === null ? null : Str::limit($reason, 60, ''),
                'updated_at' => Carbon::now(),
            ]);
    }

    private function closeStep(
        AutomationStepRun $stepRun,
        StepRunStatus $status,
        ?WorkflowEdgeKind $branch,
        ?string $error = null,
        ?string $result = null,
    ): void {
        AutomationStepRun::query()
            ->whereKey($stepRun->getKey())
            ->update([
                'status' => $status->value,
                'branch_taken' => $branch?->value,
                'completed_at' => $status->isTerminal() ? Carbon::now() : null,
                'safe_result_summary' => $result === null ? null : Str::limit($result, 250, ''),
                'safe_error_summary' => $error === null ? null : Str::limit($error, 250, ''),
                'updated_at' => Carbon::now(),
            ]);
    }
}
