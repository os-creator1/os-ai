<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M4 contract §14/§19 — mirrors FundingAttemptNotResumableException's
 * exact shape. Thrown when an owner/administrator retry is attempted
 * against a renewal charge that is not in a resumable state.
 */
class SlotRenewalChargeNotResumableException extends RuntimeException
{
    public function __construct(
        public readonly int $renewalChargeId,
        public readonly string $currentState,
    ) {
        parent::__construct("Renewal charge [{$renewalChargeId}] in state [{$currentState}] is not resumable.");
    }
}
