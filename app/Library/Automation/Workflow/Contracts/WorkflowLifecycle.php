<?php

namespace App\Library\Automation\Workflow\Contracts;

use App\Models\AutomationWorkflow;

/**
 * Automations V2 §6.3 — pause, resume, archive and stop-all.
 *
 * Implemented by V2-A, called by V2-E's endpoints. It is declared in V2-0
 * precisely so those two lanes can run in parallel: the HTTP layer never
 * dispatches enrollment work itself, it calls this.
 *
 * CONTRACT FOR THE IMPLEMENTATION — the resume rule is the delicate one:
 *
 *   `pause()`   takes the workflow row FOR UPDATE and sets `paused`. In-flight
 *               enrollments are HELD: an advance job that sees `paused` in its
 *               pre-check exits WITHOUT claiming, so no step run is written and
 *               the cursor does not move.
 *
 *   `resume()`  sets `published` in one short transaction, then — AFTER COMMIT —
 *               dispatches a bounded, self-continuing re-dispatch of every held
 *               `active` enrollment. It MUST NOT wait for the recovery sweep:
 *               WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES is a lost-job
 *               safety net, not normal resume behaviour. It must not touch
 *               `waiting` enrollments — future waits stay waiting, and ones
 *               already due are woken by the due sweep, which executes nothing
 *               itself. No provider call happens inside the resume request.
 *
 *   `archive()` and `stopAllActive()` cancel in-flight enrollments with a
 *               bounded, human-safe `exit_reason`.
 *
 * Every method is idempotent: re-dispatching enqueues work but writes no state,
 * and each piece of work is protected by the enrollment row lock plus
 * `UNIQUE(enrollment_id, node_id)`. Calling resume twice yields duplicate jobs,
 * never a duplicate step.
 */
interface WorkflowLifecycle
{
    public function pause(AutomationWorkflow $workflow): void;

    public function resume(AutomationWorkflow $workflow): void;

    public function archive(AutomationWorkflow $workflow): void;

    /** @return int how many enrollments were cancelled. */
    public function stopAllActive(AutomationWorkflow $workflow, string $exitReason): int;
}
