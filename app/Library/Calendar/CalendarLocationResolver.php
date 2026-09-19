<?php

namespace App\Library\Calendar;

use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 15 §6 — the one place this slice turns a route's
 * `{locationUid}` into an authorized `BusinessLocation`, and the one place it
 * enumerates who is currently eligible at that Location.
 *
 * TWO RULES, both from §6 and neither reimplemented anywhere else in this
 * slice:
 *
 * 1. The Location is re-derived from persistence SCOPED TO THE RESOLVED
 *    BUSINESS (`findForBusinessByUid`), never from the route alone. A
 *    sibling Location of another Business, or another Workspace's Location,
 *    therefore cannot be reached by supplying its uid — the lookup simply
 *    does not find it. Addendum §4: "Knowing or binding a record ID must
 *    never bypass Location authorization."
 * 2. `LocationAccessGuard` is then asked, fresh, every time. It is the sole
 *    authority; no parallel ACL algorithm exists in this slice.
 *
 * Both failures are a 404 (the caller aborts), never a 403 and never a
 * distinguishable error: an actor must not learn that a Location they cannot
 * reach exists.
 */
class CalendarLocationResolver
{
    public function __construct(
        private readonly BusinessLocationRepository $locationRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly LocationAccessGuard $guard,
    ) {
    }

    /**
     * The route's Location, or null when it does not belong to this
     * Business or the actor may not reach it. Callers turn null into 404.
     */
    public function resolveForActor(Business $business, string $locationUid, int $actorUserId): ?BusinessLocation
    {
        $location = $this->locationRepository->findForBusinessByUid($business, $locationUid);

        if ($location === null) {
            return null;
        }

        if (! $this->guard->userCanAccessLocation($actorUserId, $location)) {
            return null;
        }

        return $location;
    }

    /**
     * §6's eligibility rule, applied to a third party: a staff member is
     * eligible at this Location only when the guard says so RIGHT NOW,
     * re-read from persistence. Never inferred from a `booking_type_staff`
     * row, a `staff_availability_rules` row, or any other stored fact.
     */
    public function isEligible(int $staffUserId, BusinessLocation $location): bool
    {
        return $this->guard->userCanAccessLocation($staffUserId, $location);
    }

    /**
     * Everyone who could currently be scheduled at this Location: the
     * Workspace owner, the Business's own owner, and every ACTIVE Workspace
     * membership — each then filtered through the guard, because an active
     * membership alone proves nothing about Location reach
     * (`business_access_scope`/`location_access_scope` still decide).
     *
     * Presentation only. Nothing downstream may treat membership in this
     * list as authorization; every write re-derives eligibility for the
     * specific target it is about to touch.
     *
     * @return Collection<int, User>
     */
    public function eligibleStaff(Workspace $workspace, Business $business, BusinessLocation $location): Collection
    {
        $candidateIds = $this->membershipRepository->activeForWorkspace($workspace)
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->push((int) $workspace->owner_user_id)
            ->push((int) $business->customer_id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $candidateIds->all())
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $this->isEligible((int) $user->id, $location))
            ->values();
    }
}
