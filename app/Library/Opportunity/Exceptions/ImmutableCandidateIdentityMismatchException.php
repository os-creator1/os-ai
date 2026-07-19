<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::stageCandidate() when a re-staged fingerprint
 * already exists for the run but one of its immutable identity fields
 * (opportunity_run_id, type, fingerprint_version, fingerprint, context_key)
 * does not match (RFC-002 §21) — never silently overwritten.
 */
class ImmutableCandidateIdentityMismatchException extends OpportunityException
{
}
