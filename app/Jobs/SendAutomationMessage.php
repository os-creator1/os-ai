<?php

namespace App\Jobs;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Library\Automation\AutomationActionDispatcher;
use App\Library\Automation\AutomationActionResult;
use App\Library\Automation\AutomationEligibility;
use App\Library\Automation\AutomationExecutionClaimService;
use App\Models\AutomationExecution;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * B4 Business Automations — the per-execution ACTION job (contract §9,
 * §11 of the implementation task). The class name is retained from the
 * legacy worker for allowlist continuity; it now runs exactly one already-
 * claimed execution of EITHER v1 action (send_message /
 * update_contact_field) — it is no longer SMS-specific.
 *
 * Discipline (§5, §9.2, §17):
 *  1. The execution row must exist and still be Pending — a row in any
 *     other status is already terminal and is never re-run.
 *  2. EVERY authoritative object is re-fetched here: automation still
 *     active, Business + Workspace still active, entitlement still
 *     allowed, Contact still in the same Business. Any change since the
 *     claim records `skipped` with a safe reason — a stale snapshot never
 *     authorizes an external send.
 *  3. The SAME row performs its action at most once (§5.4): immediately
 *     before the action, the execution-start claim sets `started_at`
 *     exactly once under a row lock. A second worker holding the same
 *     executionId — even while the first is mid-provider-call and the row
 *     is still Pending — loses that claim and stops without side effects.
 *  4. The action (and its provider call, if any) runs OUTSIDE any DB
 *     transaction; only the short bookkeeping writes are transactional.
 *  5. A failure is recorded as `failed` and is NEVER automatically
 *     retried: `Base` already sets tries=1, the claimed row forecloses a
 *     second attempt for the same key, and `started_at` is never reset.
 */
class SendAutomationMessage extends Base
{
    public function __construct(private readonly int $executionId)
    {
        $this->onQueue('automation');
    }

    public function handle(AutomationEligibility $eligibility, AutomationActionDispatcher $dispatcher, AutomationExecutionClaimService $claims): void
    {
        $execution = AutomationExecution::query()->find($this->executionId);

        // A row that already carries started_at was handed to an action
        // once; whatever happened to that attempt, it is never re-run.
        if ($execution === null || ! $execution->isPending() || $execution->started_at !== null) {
            return;
        }

        // Checkpoint 3 (§9.1): full re-verification before any side effect.
        $reason = null;
        $resolved = $eligibility->resolve($execution->automation_id, $reason);

        if ($resolved === null) {
            $this->finish($execution, AutomationActionResult::skipped($reason ?? 'not_eligible'));

            return;
        }

        $automation = $resolved['automation'];
        $business = $resolved['business'];

        if ((int) $automation->business_id !== (int) $execution->business_id) {
            $this->finish($execution, AutomationActionResult::skipped('business_mismatch'));

            return;
        }

        $contact = Contacts::query()->find($execution->contact_id);

        if ($contact === null || ! $eligibility->contactBelongsToBusiness($contact, $business)) {
            $this->finish($execution, AutomationActionResult::skipped('contact_not_in_business'));

            return;
        }

        // Execution-start claim (§5.4): exactly one worker may proceed past
        // this line for this row, ever. Losing it means another worker is
        // (or was) already acting on it — leave the row entirely alone.
        $started = $claims->claimStart($execution->id);

        if ($started === null) {
            return;
        }

        try {
            // Outside any transaction by construction (§5.1 rule 3).
            $result = $dispatcher->dispatch($started, $automation, $business, $contact);
        } catch (Throwable $exception) {
            $result = AutomationActionResult::failed('action_exception: ' . get_class($exception));
        }

        $this->finish($execution, $result);
    }

    private function finish(AutomationExecution $execution, AutomationActionResult $result): void
    {
        DB::transaction(function () use ($execution, $result): void {
            $fresh = AutomationExecution::query()->lockForUpdate()->find($execution->id);

            if ($fresh === null || ! $fresh->isPending()) {
                return;
            }

            $fresh->update([
                'status' => $result->status->value,
                'completed_at' => now(),
                'safe_result_summary' => $result->status === AutomationExecutionStatus::Succeeded ? $result->summary : null,
                'safe_error_summary' => $result->status === AutomationExecutionStatus::Succeeded ? null : $result->summary,
            ]);
        });
    }
}
