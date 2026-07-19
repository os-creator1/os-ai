<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::snooze() when the supplied snoozedUntil is
 * not strictly later than the captured operation timestamp (RFC-002 §17,
 * §33) — the row is left completely untouched.
 */
class InvalidSnoozeUntilException extends OpportunityException
{
}
