<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessLocation;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipLocation;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 02 (Location ACL Foundation) §3/§5 — mirrors
 * WorkspaceMembershipBusinessRepository's exact method surface for the
 * new workspace_membership_locations pivot.
 */
interface WorkspaceMembershipLocationRepository extends BaseRepository
{
    public function assignedLocationIds(WorkspaceMembership $membership): Collection;

    public function isAssigned(WorkspaceMembership $membership, int $businessLocationId): bool;

    /**
     * Must verify, in order (Contract 02 §5): (a)
     * $location->business->workspace_id === $membership->workspace_id,
     * throwing CrossWorkspaceAssignmentException otherwise (the ordinary
     * Workspace-boundary check, permanent); (b) — while `main` has not yet
     * enforced 1 Workspace = 1 Business — that $location->business_id is
     * itself among the Businesses $membership can already reach via the
     * still-live business_access_scope/isAssigned() check, throwing
     * CrossBusinessLocationAssignmentException otherwise (transitional,
     * removed in Contract 14). Idempotent: assigning the same Location
     * twice returns the existing assignment rather than surfacing a raw
     * database exception.
     */
    public function assign(WorkspaceMembership $membership, BusinessLocation $location): WorkspaceMembershipLocation;

    /**
     * @param  array<int, int>  $businessLocationIds  Every ID must pass both
     *   checks assign() itself enforces (Workspace match, then the
     *   transitional Business-reach check). Every ID is validated
     *   (existence, then both checks) before any assignment row is
     *   changed — no partial sync on one invalid ID. An empty array
     *   removes every scoped grant for this membership.
     */
    public function syncForMembership(WorkspaceMembership $membership, array $businessLocationIds): Collection;

    /**
     * Deletes an assignment (grant) row only — never the Workspace,
     * Business, BusinessLocation, or WorkspaceMembership itself.
     */
    public function unassign(WorkspaceMembership $membership, int $businessLocationId): void;

    /**
     * Removes every scoped-assignment grant for every Location belonging
     * to $businessId, restricted to memberships belonging to
     * $sourceWorkspaceId — the Location-scoped equivalent of
     * removeAllForBusinessInWorkspace(), for the same Business-reassignment
     * cleanup scenario. Idempotent: returns an empty Collection when no
     * matching grants exist. Never removes a grant belonging to another
     * Workspace.
     *
     * @return Collection<int, WorkspaceMembershipLocation>
     */
    public function removeAllForBusinessInWorkspace(int $businessId, int $sourceWorkspaceId): Collection;
}
