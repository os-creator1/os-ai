<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;

/**
 * M4 contract §12/§22 — scheduled every 5 minutes exactly. Selects due
 * agreements (cancel_at_period_end, cancellation_effective_at <= now(),
 * not already canceled) and calls finalizeSlotAgreementCancellation()
 * once per agreement; creates no row itself.
 */
class FinalizeSlotAgreementCancellation extends Base
{
    public function handle(
        AdditionalBusinessSlotAgreementRepository $agreementRepository,
        UsageBillingCheckoutManager $checkoutManager,
    ): void {
        foreach ($agreementRepository->findDueForCancellationFinalization() as $agreement) {
            $checkoutManager->finalizeSlotAgreementCancellation($agreement);
        }
    }
}
