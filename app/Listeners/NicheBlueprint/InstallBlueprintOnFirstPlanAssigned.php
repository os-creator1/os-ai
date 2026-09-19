<?php

namespace App\Listeners\NicheBlueprint;

use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Jobs\NicheBlueprint\InstallNicheBlueprintForBusiness;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
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
    public function __construct(private readonly WorkspaceRepository $workspaces)
    {
    }

    public function handle(WorkspacePlanAssigned $event): void
    {
        try {
            $workspace = Workspace::query()->whereKey($event->workspaceId)->first();

            if ($workspace === null) {
                return;
            }

            $businesses = $this->workspaces->businessesForWorkspace($workspace);

            if ($businesses->count() !== 1) {
                return;
            }

            InstallNicheBlueprintForBusiness::dispatch((int) $businesses->first()->id);
        } catch (Throwable $e) {
            Log::warning('Niche Blueprint installation could not be dispatched for a first plan assignment.', [
                'workspace_id' => $event->workspaceId,
                'exception' => class_basename($e),
            ]);
        }
    }
}
