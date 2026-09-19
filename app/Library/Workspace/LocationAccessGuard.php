<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\LocationAccessDeniedException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
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

        $reach = $this->resolveLocationReach($userId, $business, $workspace);

        return match ($reach['mode']) {
            'all' => true,
            'selected' => $this->membershipLocationRepository->isAssigned($reach['membership'], $currentLocation->id),
            default => false,
        };
    }

    /**
     * Slice 18A — the ids of every Location of $business this actor may
     * access, resolved in a constant number of queries however many
     * Locations the Business has.
     *
     * This is NOT a second access algorithm. It runs the very same
     * resolveLocationReach() decision userCanAccessLocation() runs — one
     * shared, private source of truth — and only differs in how the final,
     * per-Location axis is applied: once against the whole Location list
     * instead of once per Location. A caller that would otherwise loop
     * userCanAccessLocation() over N Locations (N+1 queries) uses this
     * instead; the two are proven equivalent for every actor class by test.
     *
     * Like userCanAccessLocation() it re-derives the Business and Workspace
     * fresh, never trusts the passed-in models, and fails closed at every
     * branch. It does not filter by Location lifecycle: an archived
     * Location's access answer is the same as the per-Location method's.
     *
     * @return array<int, int> Location ids, in BusinessLocationRepository::forBusiness() order
     */
    public function accessibleLocationIdsForBusiness(int $userId, Business $business): array
    {
        $currentBusiness = $this->businessRepository->findById($business->id);

        if ($currentBusiness === null || $currentBusiness->workspace_id === null) {
            return [];
        }

        $workspace = $this->workspaceRepository->findById($currentBusiness->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return [];
        }

        $reach = $this->resolveLocationReach($userId, $currentBusiness, $workspace);

        if ($reach['mode'] === 'none') {
            return [];
        }

        $locationIds = $this->locationRepository->forBusiness($currentBusiness)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($reach['mode'] === 'all') {
            return $locationIds;
        }

        $assigned = $this->membershipLocationRepository->assignedLocationIds($reach['membership'])
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($locationIds, fn (int $id) => in_array($id, $assigned, true)));
    }

    /**
     * The whole of Contract 02 §6's authority table EXCEPT the final
     * per-Location assignment check, which the two public methods apply
     * differently (one Location vs the whole list).
     *
     * @return array{mode: 'all'|'selected'|'none', membership: ?WorkspaceMembership}
     */
    private function resolveLocationReach(int $userId, Business $business, Workspace $workspace): array
    {
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
            return ['mode' => $viewedBusinessId === (int) $business->id ? 'all' : 'none', 'membership' => null];
        }

        if ((int) $business->customer_id === $userId) {
            return ['mode' => 'all', 'membership' => null];
        }

        if ((int) $workspace->owner_user_id === $userId) {
            return ['mode' => 'all', 'membership' => null];
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return ['mode' => 'none', 'membership' => null];
        }

        $canReachBusiness = $membership->business_access_scope === WorkspaceBusinessAccessScope::All
            || $this->membershipBusinessRepository->isAssigned($membership, $business->id);

        if (! $canReachBusiness) {
            return ['mode' => 'none', 'membership' => null];
        }

        if ($membership->location_access_scope === LocationAccessScope::All) {
            return ['mode' => 'all', 'membership' => $membership];
        }

        if ($membership->location_access_scope === LocationAccessScope::Selected) {
            return ['mode' => 'selected', 'membership' => $membership];
        }

        return ['mode' => 'none', 'membership' => null];
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
