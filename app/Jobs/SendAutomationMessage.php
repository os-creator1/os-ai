<?php

namespace App\Jobs;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Library\Automation\AutomationActionDispatcher;
use App\Library\Automation\AutomationActionResult;
use App\Library\Automation\AutomationEligibility;
use App\Library\Automation\AutomationExecutionClaimService;
use App\Models\AutomationExecution;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * B4 Business Automations — the per-execution ACTION job (contract §9,
 * §11 of the implementation task). The class name is retained from the
 * legacy worker for allowlist continuity; it now runs exactly one already-
 * claimed execution of EITHER v1 action (send_message /
 * update_contact_field) — it is no longer SMS-specific.
 *
 * Discipline (§5, §9.2, §17), in execution order:
 *  1. The execution row must exist, still be Pending, and be unstarted —
 *     a row in any other status is terminal, and a row that already
 *     carries started_at was handed to an action once and is never re-run.
 *  2. The SAME row performs its action at most once (§5.4): the durable
 *     execution-start claim sets `started_at` exactly once under a row
 *     lock. A second worker holding the same executionId — even while the
 *     first is mid-provider-call and the row is still Pending — loses that
 *     claim and stops without side effects.
 *  3. AFTER that claim and immediately before the action (Correction 2),
 *     ONE final authoritative checkpoint re-fetches EVERYTHING from the
 *     database: automation exists/active, same Business as the execution,
 *     same trigger type, Business + Workspace active, entitlement allowed,
 *     Contact exists / in the Business / in the current audience group.
 *     The action receives exactly these fresh objects. Any change since
 *     the claim finishes the started row as `skipped` with a safe reason —
 *     a stale snapshot never authorizes a side effect. The bounded action
 *     handler then performs its own channel/server/sender/field checks.
 *  4. The action (and its provider call, if any) runs OUTSIDE any DB
 *     transaction, and no Automation/Business lock is held across it; only
 *     the short bookkeeping writes are transactional. This is a checkpoint
 *     guarantee, not an attempt to make revocation and provider I/O one
 *     atomic unit.
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

        if ($execution === null || ! $execution->isPending() || $execution->started_at !== null) {
            return;
        }

        // Execution-start claim (§5.4): exactly one worker may proceed past
        // this line for this row, ever. Losing it means another worker is
        // (or was) already acting on it — leave the row entirely alone.
        $started = $claims->claimStart($execution->id);

        if ($started === null) {
            return;
        }

        // FINAL authoritative checkpoint (§9, Correction 2) — post-claim,
        // pre-action, everything re-read fresh. These are the only objects
        // the action may use.
        $reason = null;
        $resolved = $eligibility->resolveForExecution($started, $reason);

        if ($resolved === null) {
            $this->finish($started, AutomationActionResult::skipped($reason ?? 'not_eligible'));

            return;
        }

        try {
            // Outside any transaction by construction (§5.1 rule 3).
            $result = $dispatcher->dispatch($started, $resolved['automation'], $resolved['business'], $resolved['contact']);
        } catch (Throwable $exception) {
            $result = AutomationActionResult::failed('action_exception: ' . get_class($exception));
        }

        $this->finish($started, $result);
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
