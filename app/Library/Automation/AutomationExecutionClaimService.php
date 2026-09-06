<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * B4 Business Automations — the ONE authoritative execution-claim seam
 * (contract §5.2, §8). Nothing else in the codebase may insert an
 * automation_executions row.
 *
 * The claim is a single INSERT guarded by the `idempotency_key` UNIQUE
 * constraint; a concurrent or later duplicate loses on the constraint and
 * is caught, never resent. That constraint — not a pre-check, not a lock —
 * is the race guarantee. Once a row exists for a key in ANY status, no
 * automatic action ever runs again for that key (§5.1).
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
     */
    public function claim(int $automationId, Contacts $contact, AutomationTriggerType $triggerType, string $idempotencyKey): ?AutomationExecution
    {
        $resolved = $this->eligibility->resolve($automationId);

        if ($resolved === null) {
            return null;
        }

        /** @var Automation $automation */
        $automation = $resolved['automation'];

        if (! $this->eligibility->contactBelongsToBusiness($contact, $resolved['business'])) {
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
