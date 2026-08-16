<?php

namespace App\Listeners\Usage;

use App\Events\Business\BusinessCreated;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Support\Facades\Log;

/**
 * Subscribed to both confirmed Business-creation events (both
 * ShouldDispatchAfterCommit — the Business row is already committed by
 * the time this listener runs, so an initialization failure here can
 * never roll back that already-created Business, and this listener does
 * not pretend otherwise). At M1, called only initializeWalletForNewBusiness().
 * Extended at M2 (RFC-005 §32) with one additional idempotent call to
 * BillingProfileManager::initializePayerAssignmentForBusiness() — an
 * ordinary incremental extension of M1's own shipped listener by the M2
 * milestone that creates business_payer_assignments, not a violation of
 * any path restriction (M1 contract §9's own established reasoning).
 *
 * On BusinessCurrencyUnresolvableException, the failure is caught and
 * logged non-sensitively rather than propagated — the Business remains
 * wallet-uninitialized (every wallet operation for it then fails closed
 * via UsageWalletNotFoundException) until an operator corrects its
 * currency data and re-runs the same idempotent backfill command used
 * for pre-existing Businesses (M1 contract §9.3). Payer-assignment
 * initialization has no such failure mode — it does not depend on the
 * wallet or its currency resolution (M2 contract §6.E) — so it always
 * runs, independent of the wallet-initialization outcome above.
 */
class InitializeBusinessUsageProfile
{
    public function __construct(
        private readonly UsageWalletManager $manager,
        private readonly BillingProfileManager $billingProfileManager,
    ) {
    }

    public function handleBusinessCreated(BusinessCreated $event): void
    {
        $this->initialize($event->businessId);
    }

    public function handleBusinessAssignedToWorkspace(BusinessAssignedToWorkspace $event): void
    {
        $this->initialize($event->businessId);
    }

    private function initialize(int $businessId): void
    {
        try {
            $this->manager->initializeWalletForNewBusiness($businessId);
        } catch (BusinessCurrencyUnresolvableException $e) {
            Log::warning('Usage wallet initialization failed: unresolvable Business currency.', [
                'business_id' => $e->businessId,
                'classification' => $e->classification,
            ]);
        }

        $this->billingProfileManager->initializePayerAssignmentForBusiness($businessId);
    }
}
