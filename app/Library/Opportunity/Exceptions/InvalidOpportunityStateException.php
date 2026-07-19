<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager's customer/admin workflow methods when an
 * Opportunity's current status is not a valid starting state for the
 * requested transition (RFC-002 §17) — the row is left completely
 * untouched, and no transition or event is written.
 */
class InvalidOpportunityStateException extends OpportunityException
{
}
