<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::failRun() when the locked run has already
 * succeeded (RFC-002 §23) — a succeeded run can never be retroactively
 * marked failed. Nothing is altered.
 */
class RunAlreadySucceededException extends OpportunityException
{
}
