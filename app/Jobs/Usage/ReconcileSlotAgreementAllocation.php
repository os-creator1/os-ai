<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;

/**
 * M4 contract §8 item 6/§21/§22 — scheduled hourly. Finds agreements
 * stuck in allocation_pending past a bounded threshold and calls
 * performVerifiedAllocation() directly, with both
 * administratorActorUserId and reason left null — no system-actor or
 * fake-administrator id of any kind. Never a fresh charge, never a new
 * idempotency key.
 */
class ReconcileSlotAgreementAllocation extends Base
{
    private const STUCK_AFTER_MINUTES = 30;

    public function handle(
        AdditionalBusinessSlotAgreementRepository $agreementRepository,
        UsageBillingCheckoutManager $checkoutManager,
    ): void {
        foreach ($agreementRepository->findStuckInAllocationPending(self::STUCK_AFTER_MINUTES) as $agreement) {
            $checkoutManager->performVerifiedAllocation($agreement);
        }
    }
}
