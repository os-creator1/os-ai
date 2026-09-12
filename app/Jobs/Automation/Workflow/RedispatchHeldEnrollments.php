<?php

namespace App\Jobs\Automation\Workflow;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Jobs\Base;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;

/**
 * Automations V2 §6.3 — what Resume actually does.
 *
 * When a workflow is paused, its in-flight journeys are simply `active`
 * enrollments that nobody is running. Resume has to go and start them again, and
 * the contract is explicit that it must NOT leave that to the stale-active
 * recovery sweep: fifteen minutes is a safety net for lost jobs, and a customer
 * who clicks Resume should see work continue at once.
 *
 * WHY ONLY `active` ROWS. `active` means the cursor is executable now; `waiting`
 * means it is parked until its time comes. So re-dispatching every `active`
 * enrollment is exactly the held set — no node inspection needed — and `waiting`
 * ones are left entirely alone: a future wait stays parked, and one that fell due
 * during the pause is woken by the due sweep, which changes state but never
 * executes anything.
 *
 * BOUNDED AND SELF-CONTINUING. It processes at most SWEEP_ENROLLMENTS_PER_RUN and
 * then re-dispatches itself from where it stopped, so a workflow with a very large
 * held population cannot produce one enormous job.
 *
 * IDEMPOTENT. It writes nothing. Resuming twice enqueues duplicate jobs, and every
 * one of those still has to win the step claim, so the worst case is wasted
 * work — never a duplicated step.
 */
class RedispatchHeldEnrollments extends Base
{
    public function __construct(
        private readonly int $workflowId,
        private readonly int $afterEnrollmentId = 0,
    ) {
        $this->onQueue('automation');
    }

    public function handle(): void
    {
        $workflow = AutomationWorkflow::query()->find($this->workflowId);

        // Paused again before this ran: the next Resume will re-dispatch.
        if ($workflow === null || ! $workflow->permitsExecution()) {
            return;
        }

        $lastId = $this->afterEnrollmentId;
        $processed = 0;

        AutomationEnrollment::query()
            ->where('workflow_id', $this->workflowId)
            ->where('status', EnrollmentStatus::Active->value)
            ->where('id', '>', $this->afterEnrollmentId)
            ->orderBy('id')
            ->limit(WorkflowLimits::SWEEP_ENROLLMENTS_PER_RUN)
            ->get(['id'])
            ->each(function ($enrollment) use (&$lastId, &$processed): void {
                AdvanceWorkflowEnrollment::dispatch((int) $enrollment->id);
                $lastId = (int) $enrollment->id;
                $processed++;
            });

        if ($processed >= WorkflowLimits::SWEEP_ENROLLMENTS_PER_RUN) {
            self::dispatch($this->workflowId, $lastId);
        }
    }
}
