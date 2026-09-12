<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowNode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automations V2 §7.3 step 2 — the durable claim, and the only place a step run
 * is created.
 *
 * THE AT-MOST-ONCE GUARANTEE LIVES HERE. The claim is one short transaction that
 * contains no I/O at all:
 *
 *   1. take a SHARED lock on the workflow row, so a concurrent pause is fully
 *      serialized against starting a step, while two enrollments of the same
 *      workflow never block each other (an exclusive lock here would serialize
 *      every step of a busy workflow for no benefit — pause takes the exclusive
 *      side);
 *   2. take an EXCLUSIVE lock on the enrollment row, which is what makes two
 *      workers holding the same enrollment id take turns;
 *   3. re-verify under those locks that the workflow is still runnable, the
 *      enrollment is still active, and its cursor is still the node we mean to
 *      run — a cursor that moved means somebody else already did this;
 *   4. INSERT the step run, already `started`, guarded by
 *      UNIQUE(enrollment_id, node_id).
 *
 * Only then, outside this transaction, does the advancer call an executor. So a
 * duplicated job cannot produce a second provider call: it either finds the
 * cursor moved, or loses the unique insert.
 *
 * B4 needed two claims for this (§5.2 and §5.4) because its flat ledger could not
 * tell "this row exists" from "this row is mine to run". Here the enrollment lock
 * plus the tree's "a node is visited once" collapse both into one insert.
 */
class WorkflowStepClaimService
{
    /**
     * Test seam, fired inside the claim transaction once both locks are held and
     * before the insert.
     *
     * A lock is only worth having if it actually excludes somebody, and the only
     * way to prove that deterministically — without sleeps, which prove nothing —
     * is to let a test act from a second database session at the exact moment the
     * lock is held. This mirrors the seam B4's own claim service already exposes
     * for the same reason (AutomationExecutionClaimService::$afterDefinitionLock).
     *
     * Null in production, and never read by any non-test caller.
     *
     * @var (\Closure(): void)|null
     */
    public static ?\Closure $afterClaimLocks = null;

    /**
     * Claim the right to run `$node` for `$enrollment`.
     *
     * @return AutomationStepRun|null null when this worker did not win the claim,
     *         in which case it must do nothing at all — not execute, not advance,
     *         not record anything.
     */
    public function claim(AutomationEnrollment $enrollment, AutomationWorkflowNode $node): ?AutomationStepRun
    {
        try {
            return DB::transaction(function () use ($enrollment, $node): ?AutomationStepRun {
                // (1) Shared: many steps may proceed together; a pause waits for
                // the in-flight ones and then blocks the next.
                $workflow = AutomationWorkflow::query()
                    ->whereKey($enrollment->workflow_id)
                    ->sharedLock()
                    ->first();

                if ($workflow === null || ! $workflow->permitsExecution()) {
                    return null;
                }

                // (2) Exclusive: this is what serializes duplicate delivery of the
                // same enrollment.
                $locked = AutomationEnrollment::query()
                    ->whereKey($enrollment->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    return null;
                }

                // (3) Everything that would make this claim wrong, re-read under
                // the locks rather than trusted from the queued payload.
                if ($locked->status !== EnrollmentStatus::Active) {
                    return null;
                }

                if ((int) $locked->current_node_id !== (int) $node->getKey()) {
                    return null;
                }

                if ((int) $locked->business_id !== (int) $workflow->business_id) {
                    return null;
                }

                if ((int) $node->version_id !== (int) $locked->version_id) {
                    // A node from another version could only arrive through a bug,
                    // and running it would execute a definition this journey is not
                    // pinned to.
                    return null;
                }

                if (self::$afterClaimLocks !== null) {
                    (self::$afterClaimLocks)();
                }

                // (4) The claim itself.
                $stepRun = new AutomationStepRun([
                    'business_id' => $locked->business_id,
                    'enrollment_id' => $locked->getKey(),
                    'node_id' => $node->getKey(),
                    'node_type' => $node->node_type,
                    'status' => StepRunStatus::Started,
                    'started_at' => Carbon::now(),
                ]);
                $stepRun->uid = (string) Str::uuid();
                $stepRun->save();

                return $stepRun;
            });
        } catch (UniqueConstraintViolationException) {
            // Another worker claimed this exact step first. Losing the race is an
            // ordinary outcome, not an error: it is precisely what stops a message
            // being sent twice.
            return null;
        }
    }
}
