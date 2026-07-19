<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::finalizeSuccessfulRun() when the locked run
 * has already failed (RFC-002 §22) — a failed run can never be
 * retroactively resurrected into succeeded. Nothing is altered.
 */
class RunAlreadyFailedException extends OpportunityException
{
}
