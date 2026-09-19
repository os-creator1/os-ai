<?php

namespace App\Listeners\NicheBlueprint;

use App\Events\Business\BusinessCreated;
use App\Jobs\NicheBlueprint\InstallNicheBlueprintForBusiness;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 20 §9.1 — trigger one of two.
 *
 * Modelled on `InitializeBusinessUsageProfile`'s documented posture, for the
 * same reasons it states: `BusinessCreated` is `ShouldDispatchAfterCommit`, so
 * the Business row is already committed by the time this runs and a failure
 * here can never roll back that Business — and this listener does not pretend
 * otherwise. Failure is caught and logged non-sensitively rather than
 * propagated into the signup request, and recovery is the same idempotent
 * re-run used for pre-existing Businesses (the `WorkspacePlanAssigned` trigger
 * or `blueprint:install-missing`).
 *
 * When the Workspace has no plan assignment yet — Agency client provisioning
 * can create a Workspace and its Business before any plan is assigned — the
 * run aborts writing ZERO records (§9.1's precondition), and the later first
 * `WorkspacePlanAssigned` performs the initial installation automatically,
 * with no operator action.
 */
class InstallBlueprintOnBusinessCreated
{
    public function handle(BusinessCreated $event): void
    {
        try {
            InstallNicheBlueprintForBusiness::dispatch($event->businessId);
        } catch (Throwable $e) {
            Log::warning('Niche Blueprint installation could not be dispatched for a new Business.', [
                'business_id' => $event->businessId,
                'exception' => class_basename($e),
            ]);
        }
    }
}
