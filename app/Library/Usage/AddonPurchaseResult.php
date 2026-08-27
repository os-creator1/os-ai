<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptState;

/**
 * M4 contract §21 — UsageBillingCheckoutManager::initiateAddonPurchase()'s
 * own return shape. An add-on purchase's own charge routes through
 * business_funding_attempts (M4 contract §18/§21), so state reflects the
 * underlying funding attempt's own state, not a separate purchase-status
 * enum.
 *
 * RFC-005 Funding Provider-Flow Correction Contract §7/§8.C —
 * redirectUrl, propagated from the underlying FundingAttemptResult, lets a
 * future, separately authorized add-on HTTP caller reach the hosted
 * Checkout URL without reaching into the gateway or manager internals.
 * Trailing/nullable so every existing positional call site remains valid
 * unchanged.
 */
final readonly class AddonPurchaseResult
{
    public function __construct(
        public int $addonPurchaseId,
        public int $fundingAttemptId,
        public FundingAttemptState $state,
        public ?string $denialReason,
        public ?string $redirectUrl = null,
    ) {
    }
}
