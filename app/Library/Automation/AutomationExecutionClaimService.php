<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Business;
use App\Models\Contacts;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * B4 Business Automations — the ONE authoritative execution-claim seam
 * (contract §5.2, §5.4, §8). Nothing else in the codebase may insert an
 * automation_executions row, and nothing else may mark one as started.
 *
 * Two distinct claims live here, deliberately in one place:
 *
 *  1. claim()      — the LOGICAL-execution claim. Expensive, lock-free
 *                    eligibility (Business/Workspace/entitlement) runs
 *                    first, OUTSIDE any transaction. Then, in one short
 *                    transaction, the Automation row (and the Contact row,
 *                    for the audience invariant) are re-read under
 *                    lockForUpdate(), the authoritative values are
 *                    verified, and the execution row is INSERTed guarded
 *                    by the `idempotency_key` UNIQUE constraint. A
 *                    definition edit cannot commit through that lock
 *                    between verification and INSERT (Correction 2), and a
 *                    concurrent duplicate loses on the constraint and is
 *                    caught, never resent. Once a row exists for a key in
 *                    ANY status, no automatic action ever runs again for
 *                    that key (§5.1).
 *  2. claimStart() — the EXECUTION-START claim (§5.4): the same ledger row
 *                    may perform its action at most once. The unique key
 *                    cannot protect against two workers receiving the same
 *                    executionId, so `started_at` is set exactly once under
 *                    a short row lock, immediately before the action, and
 *                    is never reset.
 *
 * No entitlement call and no provider/network call ever happens while a
 * lock is held; the locked sections contain only row re-reads and the
 * INSERT/UPDATE.
 *
 * Deterministic keys (§6):
 *   contact_date_reached:{automation}:{contact}:{occurrence_year}
 *   contact_created:{automation}:{contact}
 */
class AutomationExecutionClaimService
{
    /**
     * Deterministic test seams (the in-repo precedent is Agency
     * Prospecting's FakeAgencyProspectingAiClient::$beforeReturn). Never
     * set in production code; invoked, when non-null, exactly at the named
     * point so a test can interleave a concurrent state change without
     * sleeps:
     *
     *  - $beforeClaimTransaction(int $automationId): after the lock-free
     *    eligibility pass, before the locked claim transaction opens.
     *  - $afterDefinitionLock(Automation $locked): inside the claim
     *    transaction, after the Automation row lock is held, before the
     *    verification/INSERT.
     *  - $afterStartClaim(AutomationExecution $started): after claimStart()
     *    has committed, before the caller's final checkpoint.
     */
    public static ?Closure $beforeClaimTransaction = null;

    public static ?Closure $afterDefinitionLock = null;

    public static ?Closure $afterStartClaim = null;

    public function __construct(private readonly AutomationEligibility $eligibility)
    {
    }

    public static function resetTestSeams(): void
    {
        self::$beforeClaimTransaction = null;
        self::$afterDefinitionLock = null;
        self::$afterStartClaim = null;
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
        $started = DB::transaction(function () use ($executionId): ?AutomationExecution {
            $execution = AutomationExecution::query()->lockForUpdate()->find($executionId);

            if ($execution === null || ! $execution->isPending() || $execution->started_at !== null) {
                return null;
            }

            $execution->update(['started_at' => now()]);

            return $execution;
        });

        if ($started !== null && self::$afterStartClaim !== null) {
            (self::$afterStartClaim)($started);
        }

        return $started;
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
     * Claims one logical execution. Returns the newly created pending
     * execution, or null when the key was already claimed or the
     * automation is no longer eligible. Never throws on a duplicate.
     *
     * Phase 1 (lock-free, may be slow): the automation is re-read fresh and
     * must still be active, Business-scoped, and entitled (§9.1 checkpoint
     * 2) — disabling an automation before the claim MUST prevent the send
     * (§17); the Contact must belong to that Business.
     *
     * Phase 2 (locked, short, Correction 2): immediately before the INSERT,
     * the Automation row is locked and re-read so that a definition edit
     * cannot commit between verification and INSERT — the CURRENT row must
     * still describe the very trigger being claimed (active, same Business,
     * same trigger type), and the locked Contact must still be in the
     * Business and in the current audience group, if one is set. Anything
     * else returns null with no row. The unique-key INSERT happens inside
     * that same transaction; a duplicate loses on the constraint and is
     * caught here without masking any other database error.
     */
    public function claim(int $automationId, Contacts $contact, AutomationTriggerType $triggerType, string $idempotencyKey): ?AutomationExecution
    {
        // Phase 1 — lock-free eligibility (includes the entitlement call).
        $resolved = $this->eligibility->resolve($automationId);

        if ($resolved === null) {
            return null;
        }

        /** @var Business $business */
        $business = $resolved['business'];

        if (! $this->eligibility->contactBelongsToBusiness($contact, $business)) {
            return null;
        }

        // Fast path only — never the guarantee (§5.2).
        if (AutomationExecution::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        if (self::$beforeClaimTransaction !== null) {
            (self::$beforeClaimTransaction)($automationId);
        }

        // Phase 2 — the locked checkpoint + INSERT, one short transaction.
        try {
            return DB::transaction(function () use ($automationId, $business, $contact, $triggerType, $idempotencyKey): ?AutomationExecution {
                $automation = Automation::query()->lockForUpdate()->find($automationId);

                if ($automation !== null && self::$afterDefinitionLock !== null) {
                    (self::$afterDefinitionLock)($automation);
                }

                if (
                    $automation === null
                    || ! $automation->isActive()
                    || (int) $automation->business_id !== (int) $business->id
                    || $automation->trigger_type !== $triggerType
                ) {
                    return null;
                }

                $lockedContact = Contacts::query()->lockForUpdate()->find($contact->id);

                if (
                    $lockedContact === null
                    || (int) $lockedContact->business_id !== (int) $automation->business_id
                    || ! $this->eligibility->contactInAudience($automation, $lockedContact)
                ) {
                    return null;
                }

                return AutomationExecution::create([
                    'business_id' => $automation->business_id,
                    'automation_id' => $automation->id,
                    'contact_id' => $lockedContact->id,
                    'trigger_type' => $triggerType->value,
                    'idempotency_key' => $idempotencyKey,
                    'status' => AutomationExecutionStatus::Pending->value,
                    'action_claimed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Already claimed by a concurrent or earlier run — never send.
            return null;
        }
    }
}
