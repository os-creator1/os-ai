<?php

namespace App\Listeners\NicheBlueprint;

use App\Events\Business\BusinessUpdated;
use App\Jobs\NicheBlueprint\InstallNicheBlueprintForBusiness;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the gap behind Agency-provisioned client Businesses.
 *
 * A client Business is created with the placeholder industry `other` (no Blueprint
 * resolves, nothing is written) and receives its real industry later, with
 * `BusinessUpdated`. Neither §9.1 trigger fires then, so the client never received
 * its niche Blueprint. This listener dispatches the SAME idempotent entry point when
 * the `industry` field changed.
 *
 * Safe by construction: the engine pins an already-provisioned Business to its own
 * records (a re-run is a no-op for it), aborts writing nothing when no plan is
 * assigned yet (the first-plan trigger covers that ordering) or no Blueprint matches
 * the new industry, and never touches a Website. Failure is logged, never propagated
 * into the profile-update request.
 */
class InstallBlueprintOnBusinessIndustryChanged
{
    public function handle(BusinessUpdated $event): void
    {
        if (! in_array('industry', $event->changedFields, true)) {
            return;
        }

        try {
            InstallNicheBlueprintForBusiness::dispatch($event->businessId);
        } catch (Throwable $e) {
            Log::error('Niche Blueprint installation could not be dispatched for a Business industry change.', [
                'business_id' => $event->businessId,
                'exception' => class_basename($e),
            ]);
        }
    }
}
