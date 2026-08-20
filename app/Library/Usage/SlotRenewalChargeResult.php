<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptState;

/**
 * M4 contract §21 — UsageBillingCheckoutManager's own shared return shape
 * for every renewal-charge-creating/retrying operation
 * (createScheduledRenewalCharge(), requestSlotAgreementIncrease(),
 * retrySlotRenewalAsOwner(), retrySlotRenewalAsAdministrator()). Reuses
 * FundingAttemptState (M4 contract §18/§19) — the renewal charge's own
 * state column shape.
 */
final readonly class SlotRenewalChargeResult
{
    public function __construct(
        public int $renewalChargeId,
        public FundingAttemptState $state,
        public ?string $denialReason,
    ) {
    }
}
