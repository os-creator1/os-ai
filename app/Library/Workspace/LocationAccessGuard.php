<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\LocationAccessDeniedException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;

/**
 * Implementation Contract 02 (Location ACL Foundation) §4/§6 — a new,
 * genuinely separate sibling authority to WorkspaceManager
 * ::userCanAccessBusiness(), not more methods added to WorkspaceManager
 * itself (Addendum §4's own "sibling authority, not a second tenancy
 * system" framing). Mirrors userCanAccessBusiness()'s exact fail-closed
 * shape: re-derive every fact fresh from its repository, never trust a
 * passed-in model, default to false at every branch.
 *
 * Does not wire into any controller — Contract 08B does that.
 */
class LocationAccessGuard
{
    public function __construct(
        private readonly BusinessLocationRepository $locationRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
        private readonly WorkspaceMembershipLocationRepository $membershipLocationRepository,
        private readonly BusinessRouteAccess $businessRouteAccess,
    ) {
    }

    /**
     * Contract 02 §6's authority table, exactly:
     *
     *  - Workspace owner: always full access, unconditionally.
     *  - Direct Business owner (business.customer_id === userId): full access.
     *  - Active membership: Business reach is re-checked on every call —
     *    business_access_scope = All, or an explicit
     *    workspace_membership_businesses grant for this Business — and
     *    denied immediately if that reach no longer holds. This is the
     *    §5 transitional invariant enforced continuously, not only at
     *    Location-grant creation time: a Location axis can never be wider
     *    than the still-live Business axis, even if the Business grant is
     *    narrowed or removed after the Location grant was made.
     *  - Business reach confirmed, location_access_scope = All: full
     *    access to every Location of that Business.
     *  - Business reach confirmed, location_access_scope = Selected: only
     *    Locations with an explicit workspace_membership_locations grant
     *    row.
     *  - Inactive membership, or no membership at all: no access.
     *
     * That table is for ORDINARY requests and is unchanged. An active
     * cross-Workspace Agency View As replaces it wholesale for as long as it
     * lasts — see the branch below — because the actor is deliberately none of
     * the things it asks about.
     *
     * Re-derives $location fresh from its own repository — never trusts
     * the passed-in instance — then resolves business/workspace the same
     * defensive way, exact precedent from userCanAccessBusiness()'s own
     * $currentBusiness = $this->businessRepository->findById(...)
     * re-derivation. "Knowing a record ID never bypasses this" (Addendum
     * §4) is enforced structurally by this re-derivation, not a new
     * mechanism.
     */
    public function userCanAccessLocation(int $userId, BusinessLocation $location): bool
    {
        $currentLocation = $this->locationRepository->query()->find($location->id);

        if ($currentLocation === null || $currentLocation->business_id === null) {
            return false;
        }

        $business = $this->businessRepository->findById($currentLocation->business_id);

        if ($business === null || $business->workspace_id === null) {
            return false;
        }

        $workspace = $this->workspaceRepository->findById($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return false;
        }

        // V1 Contract 04 — a cross-Workspace Agency View As deliberately makes
        // the actor no ordinary tenant of the viewed Client Workspace, so
        // every row of the table below correctly refuses them: they own no
        // Business here, own no Workspace here and hold no membership here.
        // While such a session is active the viewed Client's own Locations are
        // nevertheless theirs to work in, exactly as the Client would.
        //
        // The session is therefore the WHOLE answer while it lasts, in both
        // directions: the viewed Business's Locations are reachable, and every
        // other Business's are refused — including Locations of a Business
        // this actor ordinarily owns outright, because View As only narrows.
        // Nothing is written to workspace_memberships or
        // workspace_membership_locations to achieve it, and no Agency
        // relationship is read here: BusinessRouteAccess asks the one
        // canonical authority (ViewAsManager::current(), revalidated per
        // request) and hands back only which Business it names.
        $viewedBusinessId = $this->businessRouteAccess->viewedBusinessIdFor($userId);

        if ($viewedBusinessId !== null) {
            return $viewedBusinessId === (int) $business->id;
        }

        if ((int) $business->customer_id === $userId) {
            return true;
        }

        if ((int) $workspace->owner_user_id === $userId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return false;
        }

        $canReachBusiness = $membership->business_access_scope === WorkspaceBusinessAccessScope::All
            || $this->membershipBusinessRepository->isAssigned($membership, $business->id);

        if (! $canReachBusiness) {
            return false;
        }

        if ($membership->location_access_scope === LocationAccessScope::All) {
            return true;
        }

        if ($membership->location_access_scope === LocationAccessScope::Selected) {
            return $this->membershipLocationRepository->isAssigned($membership, $currentLocation->id);
        }

        return false;
    }

    /**
     * Delegates entirely to userCanAccessLocation() — no second,
     * independent access algorithm, matching
     * assertUserCanAccessBusiness()'s own precedent exactly.
     */
    public function assertUserCanAccessLocation(int $userId, BusinessLocation $location): void
    {
        if (! $this->userCanAccessLocation($userId, $location)) {
            throw new LocationAccessDeniedException($userId, $location->id);
        }
    }

    /**
     * Implementation Contract 19 §5.2/§5.8 — the SET of this Business's
     * Locations the actor may read, ascending.
     *
     * WHY IT LIVES HERE. Contract 19 R-0 forbids a second Location ACL, and
     * the COO's authorization-scope fingerprint needs the actor's authorized
     * set, not a per-Location question. Asking userCanAccessLocation() once
     * per Location would answer it, but re-derives the Business, Workspace and
     * membership on every call; so this method walks the SAME authority table
     * once, in the SAME branch order, and returns the set. It is the one
     * authority's set-shaped reader, not a parallel algorithm — and
     * LocationAccessSetConsistencyTest asserts it agrees with
     * userCanAccessLocation() for every Location, so it cannot drift.
     *
     * Same fail-closed shape as its sibling: re-derive every fact fresh from
     * its repository, never trust the passed-in model, default to an empty set
     * at every branch.
     *
     * @return array<int, int>
     */
    public function authorizedLocationIdsFor(int $userId, Business $business): array
    {
        $currentBusiness = $this->businessRepository->findById((int) $business->id);

        if ($currentBusiness === null || $currentBusiness->workspace_id === null) {
            return [];
        }

        $workspace = $this->workspaceRepository->findById($currentBusiness->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return [];
        }

        $locationIds = $this->locationRepository->forBusiness($currentBusiness)
            ->map(static fn (BusinessLocation $location): int => (int) $location->id)
            ->all();

        if ($locationIds === []) {
            return [];
        }

        sort($locationIds, SORT_NUMERIC);

        // Contract 04 — while a cross-Workspace Agency View As is active it is
        // the whole answer, in both directions: the viewed Business's
        // Locations are reachable and every other Business's are refused,
        // including ones this actor ordinarily owns outright.
        $viewedBusinessId = $this->businessRouteAccess->viewedBusinessIdFor($userId);

        if ($viewedBusinessId !== null) {
            return $viewedBusinessId === (int) $currentBusiness->id ? $locationIds : [];
        }

        if ((int) $currentBusiness->customer_id === $userId) {
            return $locationIds;
        }

        if ((int) $workspace->owner_user_id === $userId) {
            return $locationIds;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return [];
        }

        $canReachBusiness = $membership->business_access_scope === WorkspaceBusinessAccessScope::All
            || $this->membershipBusinessRepository->isAssigned($membership, $currentBusiness->id);

        if (! $canReachBusiness) {
            return [];
        }

        if ($membership->location_access_scope === LocationAccessScope::All) {
            return $locationIds;
        }

        if ($membership->location_access_scope === LocationAccessScope::Selected) {
            $assigned = $this->membershipLocationRepository->assignedLocationIds($membership)
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            return array_values(array_intersect($locationIds, $assigned));
        }

        return [];
    }
}
