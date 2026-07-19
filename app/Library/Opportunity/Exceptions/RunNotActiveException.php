<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::stageCandidate() when the locked run no
 * longer exists, or its status is not `running` (RFC-002 §21).
 */
class RunNotActiveException extends OpportunityException
{
}
