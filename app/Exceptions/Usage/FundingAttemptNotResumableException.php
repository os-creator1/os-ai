<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §17 — thrown when a platform administrator (or any actor)
 * attempts to admin_retry a funding attempt that is not in a resumable
 * state (i.e., not created/provider_pending/requires_action/processing/
 * failed) — an administrator may only resume an already-authorized
 * attempt, never originate a new one (M3 contract §9, RFC-005 §16).
 */
class FundingAttemptNotResumableException extends RuntimeException
{
    public function __construct(
        public readonly int $fundingAttemptId,
        public readonly string $currentState,
    ) {
        parent::__construct("Funding attempt [{$fundingAttemptId}] in state [{$currentState}] is not resumable.");
    }
}
