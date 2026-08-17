<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptState;

/**
 * M3 contract §11 — UsageBillingCheckoutManager's own return shape for a
 * funding-attempt-creating/state-transitioning operation.
 */
final readonly class FundingAttemptResult
{
    public function __construct(
        public int $fundingAttemptId,
        public FundingAttemptState $state,
        public ?string $denialReason,
    ) {
    }
}
