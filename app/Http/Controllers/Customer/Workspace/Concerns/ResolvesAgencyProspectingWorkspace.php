<?php

namespace App\Http\Controllers\Customer\Workspace\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * Runtime pass — extracted verbatim (behavior-preserving, zero logic
 * change) from AgencyProspectingController so the Channels sub-page
 * (AgencyProspectingChannelController) can never drift from the exact
 * same Workspace-role + active-Workspace + ProspectOutreach-entitlement
 * invariant the rest of Prospecting already enforces. This is the same
 * feature's own resolver, not a new cross-feature abstraction — both
 * controllers require the constructor-promoted
 * WorkspaceRepository/WorkspaceMembershipRepository/EntitlementManager
 * this trait's methods read from `$this`.
 */
trait ResolvesAgencyProspectingWorkspace
{
    /**
     * The sole resolver for every action: the Workspace must exist and be
     * active (Workspace.is_active is independent of, and never inferred
     * from, WorkspacePlanAssignment.status — WorkspaceRepository::
     * allForUser() deliberately returns a Workspace regardless of its own
     * active state, so this boundary enforces is_active itself rather
     * than relying on that repository), the actor must hold the
     * Workspace-role invariant (owner or active Admin; Staff denied by
     * default), AND the independent RFC-004 entitlement decision must
     * pass. Centralizing all three checks here means the invariant cannot
     * drift between actions — the same discipline B2's
     * resolveOwnedConnection() correction established for its own
     * connection-specific actions.
     */
    private function resolveEntitledWorkspace(string $workspaceUid): Workspace
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active || ! $this->hasProspectingRole($workspace, (int) Auth::id())) {
            abort(404);
        }

        $decision = $this->entitlementManager->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value);

        if (! $decision->allowed) {
            abort(404);
        }

        return $workspace;
    }

    /**
     * Owner or active Workspace Admin only — ordinary Staff denied by
     * default, per the foundation task's own instruction: no existing
     * Workspace-role mechanic establishes a narrower, appropriate Staff
     * grant for a product this sensitive (external outbound messaging on
     * the Agency's own behalf), so this defaults closed rather than
     * reusing Business-access-scope staff grants, which govern access to
     * client Businesses, not the Agency's own acquisition system.
     */
    private function hasProspectingRole(Workspace $workspace, int $userId): bool
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        return $membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin;
    }

    /**
     * @return array<int, Workspace>
     */
    private function accessibleWorkspaces(): array
    {
        $userId = (int) Auth::id();

        return $this->workspaceRepository->allForUser($userId)
            ->filter(fn (Workspace $workspace) => $workspace->is_active)
            ->filter(fn (Workspace $workspace) => $this->hasProspectingRole($workspace, $userId))
            ->filter(fn (Workspace $workspace) => $this->entitlementManager
                ->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed)
            ->values()
            ->all();
    }
}
