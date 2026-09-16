<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\LocationAccessDeniedException;
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
    ) {
    }

    /**
     * Contract 02 §6's authority table, exactly:
     *
     *  - Workspace owner: always full access, unconditionally.
     *  - Direct Business owner (business.customer_id === userId): full access.
     *  - Active membership, location_access_scope = All: full access to
     *    every Location of every Business the membership can already
     *    reach — composed with the §5 transitional check (never wider
     *    than the still-live business_access_scope grant).
     *  - Active membership, location_access_scope = Selected: only
     *    Locations with an explicit workspace_membership_locations grant
     *    row (the transitional check is already enforced at grant-
     *    creation time by the repository's own assign()/syncForMembership(),
     *    so it is not re-derived a second time here).
     *  - Inactive membership, or no membership at all: no access.
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

        if ($membership->location_access_scope === LocationAccessScope::All) {
            return $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                || $this->membershipBusinessRepository->isAssigned($membership, $business->id);
        }

        return $this->membershipLocationRepository->isAssigned($membership, $currentLocation->id);
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
}
