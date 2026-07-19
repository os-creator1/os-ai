<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::stageCandidate() for any candidate-level
 * validation failure that is not evidence-specific (RFC-002 §34, §45):
 * out-of-range impact/urgency/effort/confidence, a non-empty
 * templateParameters/actionParameters payload, a non-null context, an
 * unknown or disallowed relevantGoalKey, a missing Business, or an
 * action-registry integrity mismatch.
 */
class InvalidOpportunityCandidateException extends OpportunityException
{
}
