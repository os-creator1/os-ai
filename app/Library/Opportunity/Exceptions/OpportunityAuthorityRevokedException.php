<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(2) — shared base for every refusal raised
 * because MUTABLE AUTHORITY changed between an approval and the moment it
 * would take effect.
 *
 * WHY IT EXTENDS OpportunityActionNotExecutableException. The queued
 * ExecuteOpportunityAction already treats that type as a pre-invocation
 * state mismatch: it records a clean, allowlisted failure summary inside the
 * same transaction and does NOT rethrow
 * (`ExecuteOpportunityAction.php:88-100`). Every guard below therefore
 * inherits exactly the behaviour a revoked-authority refusal needs — nothing
 * was mutated, the execution is recorded failed, and the job is not retried
 * three times over a condition that will not fix itself.
 *
 * WHY THE SUBCLASSES ARE DISTINCT. §13(2) requires "four separate refusals,
 * four typed reasons": a test (and a future operator) must be able to tell
 * *which* authority disappeared, so each gate throws its own type rather
 * than a shared one carrying a string.
 */
abstract class OpportunityAuthorityRevokedException extends OpportunityActionNotExecutableException
{
}
