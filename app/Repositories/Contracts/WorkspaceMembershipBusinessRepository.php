<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Support\Collection;

interface WorkspaceMembershipBusinessRepository extends BaseRepository
{
    public function assignedBusinessIds(WorkspaceMembership $membership): Collection;

    public function isAssigned(WorkspaceMembership $membership, int $businessId): bool;

    /**
     * Must verify $business->workspace_id === $membership->workspace_id before
     * writing, and throw CrossWorkspaceAssignmentException otherwise (RFC-003
     * §7.5, §9.3) — no plain foreign key expresses that cross-table equality.
     * Idempotent: assigning the same Business twice returns the existing
     * assignment rather than surfacing a raw database exception.
     */
    public function assign(WorkspaceMembership $membership, Business $business): WorkspaceMembershipBusiness;

    /**
     * @param  array<int, int>  $businessIds  Every ID must belong to the
     *   membership's Workspace (same check as assign()). Every ID is
     *   validated (existence, then Workspace match) before any assignment
     *   row is changed — no partial sync on one invalid ID. An empty array
     *   removes every scoped grant for this membership.
     */
    public function syncForMembership(WorkspaceMembership $membership, array $businessIds): Collection;

    /**
     * Deletes an assignment (grant) row only — never the Workspace,
     * Business, or WorkspaceMembership itself (RFC-003 §12.3, §17).
     */
    public function unassign(WorkspaceMembership $membership, int $businessId): void;

    /**
     * Removes every scoped-assignment grant for $businessId, restricted to
     * memberships belonging to $sourceWorkspaceId (RFC-003 Milestone 2
     * domain contract, corrected report §5 — reassignBusiness() cleanup
     * only; the general per-membership sync path remains
     * syncForMembership()). Idempotent: returns an empty Collection when
     * no matching grants exist. Never removes a grant belonging to another
     * Workspace. WorkspaceManager must never delete from
     * workspace_membership_businesses directly — this is the only
     * sanctioned path for a reassignment-triggered bulk removal.
     *
     * @return Collection<int, WorkspaceMembershipBusiness>
     */
    public function removeAllForBusinessInWorkspace(int $businessId, int $sourceWorkspaceId): Collection;
}
