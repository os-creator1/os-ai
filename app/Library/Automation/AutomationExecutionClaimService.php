<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * B4 Business Automations — the ONE authoritative execution-claim seam
 * (contract §5.2, §5.4, §8). Nothing else in the codebase may insert an
 * automation_executions row, and nothing else may mark one as started.
 *
 * Two distinct claims live here, deliberately in one place:
 *
 *  1. claim()      — the LOGICAL-execution claim: a single INSERT guarded
 *                    by the `idempotency_key` UNIQUE constraint. A
 *                    concurrent or later duplicate loses on the constraint
 *                    and is caught, never resent. Once a row exists for a
 *                    key in ANY status, no automatic action ever runs again
 *                    for that key (§5.1).
 *  2. claimStart() — the EXECUTION-START claim (§5.4): the same ledger row
 *                    may perform its action at most once. The unique key
 *                    cannot protect against two workers receiving the same
 *                    executionId, so `started_at` is set exactly once under
 *                    a short row lock, immediately before the action, and
 *                    is never reset.
 *
 * Deterministic keys (§6):
 *   contact_date_reached:{automation}:{contact}:{occurrence_year}
 *   contact_created:{automation}:{contact}
 */
class AutomationExecutionClaimService
{
    public function __construct(private readonly AutomationEligibility $eligibility)
    {
    }

    /**
     * Contract §5.4 — acquire the one-time execution-start claim. Only a
     * Pending row whose `started_at` is still NULL can be started; the
     * update happens under `lockForUpdate()` in its own short transaction,
     * so of any number of workers holding the same executionId exactly one
     * observes NULL and wins. Everyone else receives null and must stop
     * without touching the row. `started_at` is never cleared or retried:
     * a process that dies after this claim and before its provider call
     * loses the action by design (§5.1 rule 4) and the row honestly stays
     * Pending.
     */
    public function claimStart(int $executionId): ?AutomationExecution
    {
        return DB::transaction(function () use ($executionId): ?AutomationExecution {
            $execution = AutomationExecution::query()->lockForUpdate()->find($executionId);

            if ($execution === null || ! $execution->isPending() || $execution->started_at !== null) {
                return null;
            }

            $execution->update(['started_at' => now()]);

            return $execution;
        });
    }

    public static function dateReachedKey(int $automationId, int $contactId, int $occurrenceYear): string
    {
        return sprintf('contact_date_reached:%d:%d:%d', $automationId, $contactId, $occurrenceYear);
    }

    public static function contactCreatedKey(int $automationId, int $contactId): string
    {
        return sprintf('contact_created:%d:%d', $automationId, $contactId);
    }

    /**
     * Claims one logical execution. Immediately before inserting, the
     * automation is re-read fresh and must still be active, Business-
     * scoped, and entitled (§9.1 checkpoint 2) — disabling an automation
     * before the claim MUST prevent the send (§17). Returns the newly
     * created pending execution, or null when the key was already claimed
     * or the automation is no longer eligible. Never throws on a duplicate.
     *
     * Stale-definition guard (§5.2): an evaluator may have observed the
     * definition before it was edited, so the CURRENT row must still
     * describe the very trigger being claimed — same trigger type, same
     * Business as the Contact, and (when the current trigger restricts its
     * audience) the Contact still in that group. Anything else is a stale
     * candidate and is dropped without a row.
     */
    public function claim(int $automationId, Contacts $contact, AutomationTriggerType $triggerType, string $idempotencyKey): ?AutomationExecution
    {
        $resolved = $this->eligibility->resolve($automationId);

        if ($resolved === null) {
            return null;
        }

        /** @var Automation $automation */
        $automation = $resolved['automation'];

        if ($automation->trigger_type !== $triggerType) {
            return null;
        }

        if (! $this->eligibility->contactBelongsToBusiness($contact, $resolved['business'])) {
            return null;
        }

        $audienceGroupId = $automation->trigger_config['contact_group_id'] ?? null;

        if ($audienceGroupId !== null && (int) $audienceGroupId !== (int) $contact->group_id) {
            return null;
        }

        // Fast path only — never the guarantee (§5.2).
        if (AutomationExecution::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        try {
            return AutomationExecution::create([
                'business_id' => $automation->business_id,
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'trigger_type' => $triggerType->value,
                'idempotency_key' => $idempotencyKey,
                'status' => AutomationExecutionStatus::Pending->value,
                'action_claimed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already claimed by a concurrent or earlier run — never send.
            return null;
        }
    }
}
