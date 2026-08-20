<?php

namespace App\Library\Usage;

use App\Enums\Usage\SlotAgreementState;

/**
 * M4 contract §21 — UsageBillingCheckoutManager::quoteAdditionalSlotAgreement()'s
 * own return shape.
 */
final readonly class SlotAgreementQuoteResult
{
    public function __construct(
        public int $agreementId,
        public SlotAgreementState $state,
        public int $pricePerSlotMicroSnapshot,
        public int $totalAmountMicroSnapshot,
        public int $paidDelta,
    ) {
    }
}
