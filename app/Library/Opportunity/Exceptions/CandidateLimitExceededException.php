<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::stageCandidate() when a genuinely new
 * fingerprint would exceed config('opportunity.max_candidates_per_run')
 * (RFC-002 §21). Re-staging an already-staged fingerprint never consumes a
 * slot and never throws this.
 */
class CandidateLimitExceededException extends OpportunityException
{
}
