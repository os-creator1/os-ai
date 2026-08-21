<?php

namespace App\Library\Usage;

use App\Enums\Usage\SlotAgreementState;

/**
 * M4 contract §21 — UsageBillingCheckoutManager::initiateSlotAgreementCheckout()/
 * confirmSlotAgreementFromReturn()'s own shared return shape.
 * redirectUrl is populated only by initiateSlotAgreementCheckout() (the
 * Checkout Session's own hosted URL the browser must continue to); a
 * confirmation-path result never repopulates it (M4 contract §15a).
 */
final readonly class SlotAgreementCheckoutResult
{
    public function __construct(
        public int $agreementId,
        public SlotAgreementState $state,
        public ?string $redirectUrl,
        public ?string $denialReason,
    ) {
    }
}
