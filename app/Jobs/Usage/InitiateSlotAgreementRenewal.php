<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;

/**
 * M4 contract §11/§22 — scheduled every 5 minutes exactly. Selects due
 * agreements (next_renewal_at <= now(), not pending cancellation, not
 * lapsed, completed) and calls createScheduledRenewalCharge() once per
 * agreement; creates no row itself.
 */
class InitiateSlotAgreementRenewal extends Base
{
    public function handle(
        AdditionalBusinessSlotAgreementRepository $agreementRepository,
        UsageBillingCheckoutManager $checkoutManager,
    ): void {
        foreach ($agreementRepository->findDueForRenewal() as $agreement) {
            $checkoutManager->createScheduledRenewalCharge($agreement);
        }
    }
}
