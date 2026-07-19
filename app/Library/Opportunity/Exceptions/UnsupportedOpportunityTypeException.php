<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::stageCandidate() when the (worker_key,
 * type) pair is not registered in OpportunityTypeRegistry (RFC-002 §13.2,
 * §21).
 */
class UnsupportedOpportunityTypeException extends OpportunityException
{
}
