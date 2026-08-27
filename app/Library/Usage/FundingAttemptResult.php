<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptState;

/**
 * M3 contract §11 — UsageBillingCheckoutManager's own return shape for a
 * funding-attempt-creating/state-transitioning operation.
 *
 * RFC-005 Funding Provider-Flow Correction Contract §8.C — redirectUrl is
 * populated only by the Checkout-Session-creating branch (ManualTopUp/
 * AddonPurchase); null for every other path (AutoRecharge, and any
 * already-succeeded/failed/denied early return), mirroring
 * SlotAgreementCheckoutResult's own precedent. Trailing/nullable so every
 * existing positional call site remains valid unchanged.
 */
final readonly class FundingAttemptResult
{
    public function __construct(
        public int $fundingAttemptId,
        public FundingAttemptState $state,
        public ?string $denialReason,
        public ?string $redirectUrl = null,
    ) {
    }
}
