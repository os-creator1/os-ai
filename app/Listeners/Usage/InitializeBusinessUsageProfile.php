<?php

namespace App\Listeners\Usage;

use App\Events\Business\BusinessCreated;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Support\Facades\Log;

/**
 * Subscribed to both confirmed Business-creation events (both
 * ShouldDispatchAfterCommit — the Business row is already committed by
 * the time this listener runs, so a wallet-initialization failure here
 * can never roll back that already-created Business, and this listener
 * does not pretend otherwise). At M1, calls only
 * initializeWalletForNewBusiness() — never a payer assignment (that is
 * M2's own extension of this same class, not authorized here).
 *
 * On BusinessCurrencyUnresolvableException, the failure is caught and
 * logged non-sensitively rather than propagated — the Business remains
 * wallet-uninitialized (every wallet operation for it then fails closed
 * via UsageWalletNotFoundException) until an operator corrects its
 * currency data and re-runs the same idempotent backfill command used
 * for pre-existing Businesses (M1 contract §9.3).
 */
class InitializeBusinessUsageProfile
{
    public function __construct(private readonly UsageWalletManager $manager)
    {
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
    }
}
