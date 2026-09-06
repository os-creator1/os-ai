<?php

namespace App\Jobs;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Library\Automation\AutomationActionDispatcher;
use App\Library\Automation\AutomationActionResult;
use App\Library\Automation\AutomationEligibility;
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
 *  3. The action (and its provider call, if any) runs OUTSIDE any DB
 *     transaction; only the short bookkeeping writes are transactional.
 *  4. A failure is recorded as `failed` and is NEVER automatically
 *     retried: `Base` already sets tries=1, and the claimed row itself
 *     forecloses a second attempt for the same key.
 */
class SendAutomationMessage extends Base
{
    public function __construct(private readonly int $executionId)
    {
        $this->onQueue('automation');
    }

    public function handle(AutomationEligibility $eligibility, AutomationActionDispatcher $dispatcher): void
    {
        $execution = AutomationExecution::query()->find($this->executionId);

        if ($execution === null || ! $execution->isPending()) {
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

        DB::transaction(function () use ($execution): void {
            $execution->update(['started_at' => now()]);
        });

        try {
            // Outside any transaction by construction (§5.1 rule 3).
            $result = $dispatcher->dispatch($execution, $automation, $business, $contact);
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
