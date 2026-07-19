<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::failRun() (and later finalizeSuccessfulRun())
 * when the locked run row no longer exists (RFC-002 §23).
 */
class RunNotFoundException extends OpportunityException
{
}
