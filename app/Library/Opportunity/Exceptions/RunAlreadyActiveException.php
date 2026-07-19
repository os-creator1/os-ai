<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::beginRun() when a healthy (not timed-out)
 * running run already exists for the same Business/worker (RFC-002 §24.3).
 */
class RunAlreadyActiveException extends OpportunityException
{
}
