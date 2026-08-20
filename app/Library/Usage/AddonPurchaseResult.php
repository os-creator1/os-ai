<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptState;

/**
 * M4 contract §21 — UsageBillingCheckoutManager::initiateAddonPurchase()'s
 * own return shape. An add-on purchase's own charge routes through
 * business_funding_attempts (M4 contract §18/§21), so state reflects the
 * underlying funding attempt's own state, not a separate purchase-status
 * enum.
 */
final readonly class AddonPurchaseResult
{
    public function __construct(
        public int $addonPurchaseId,
        public int $fundingAttemptId,
        public FundingAttemptState $state,
        public ?string $denialReason,
    ) {
    }
}
