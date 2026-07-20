<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityActionExecutor when a Business mutation was applied
 * through BusinessManager but the post-mutation live-state recheck does not
 * confirm the expected result (RFC-002 §30.2, §32 system_verified) — the
 * mutation has already happened; this signals the caller (future
 * ExecuteOpportunityAction) that the execution must be recorded as failed,
 * never silently reported as succeeded.
 */
class OpportunityActionVerificationException extends OpportunityException
{
}
