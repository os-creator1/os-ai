<?php

namespace App\Listeners\NicheBlueprint;

use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Jobs\NicheBlueprint\InstallNicheBlueprintForBusiness;
use App\Models\Business;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 20 §9.1 — trigger two of two.
 *
 * `WorkspacePlanAssigned` is dispatched by `EntitlementManager`'s FIRST-plan
 * paths only (`assignFirstPlan()` and the legacy onboarding compatibility
 * assignment). This listener subscribes to it and never modifies it.
 *
 * **`WorkspacePlanChanged` is deliberately NOT wired here, and that omission
 * is the specification, not an oversight.** Wiring it would silently install
 * newly entitled components into an established Business on every upgrade —
 * the single thing Addendum §16 forbids outright. Upgrade stays pure-read
 * surfacing (§8.1); downgrade stays inert (§8.2). §13.5's case E is the test
 * that proves this listener list is what it claims to be.
 *
 * Resolves the Workspace's CANONICAL SOLE Business and no-ops when there is
 * none — zero Businesses (the plan landed before the Business, which the
 * `BusinessCreated` trigger then handles) or more than one (an integrity
 * contradiction to fail closed on, never to resolve by picking one, following
 * the established sole-Business precedent).
 */
class InstallBlueprintOnFirstPlanAssigned
{
    public function handle(WorkspacePlanAssigned $event): void
    {
        try {
            // A DIRECT, AUTHORITATIVE READ, deliberately not the repository's
            // request-memoized businessesForWorkspace(). This listener is the
            // ONLY automatic recovery for the "Business created before its
            // plan" ordering, and a memo populated earlier in the same request
            // — while the Workspace still held zero Businesses — would make it
            // silently return here, leaving the account with no Blueprint and
            // no remaining automatic retry. `limit(2)` is enough to tell
            // "exactly one" from "more than one" without loading a fleet.
            $businessIds = Business::query()
                ->where('workspace_id', $event->workspaceId)
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');

            // Zero (the Business has not been created yet — `BusinessCreated`
            // will handle it) or more than one (an integrity contradiction to
            // fail closed on, never to resolve by picking one).
            if ($businessIds->count() !== 1) {
                return;
            }

            InstallNicheBlueprintForBusiness::dispatch((int) $businessIds->first());
        } catch (Throwable $e) {
            // Logged at error, not warning: §9.1 requires this failure never
            // to propagate into the request that assigned the plan, but a
            // systemic dispatch outage here silently denies every new account
            // its Blueprint, so it must be alertable rather than merely noted.
            Log::error('Niche Blueprint installation could not be dispatched for a first plan assignment.', [
                'workspace_id' => $event->workspaceId,
                'exception' => class_basename($e),
            ]);
        }
    }
}
