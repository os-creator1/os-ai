<?php

namespace App\Library\Calendar;

use App\Exceptions\Calendar\StaffAvailabilityAuthorityException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\StaffAvailabilityRule;
use App\Models\StaffTimeOff;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 15 §5.2, §5.3, §6 — recurring availability windows
 * and User-global time off, plus the one implementation of §6's
 * availability-authority table.
 *
 * THE AUTHORITY TABLE (§6), settled for V1 and implemented literally:
 *
 *   Workspace or Business Owner -> any staff member CURRENTLY ELIGIBLE at
 *                                  the target Location
 *   Admin                       -> themselves only
 *   Staff                       -> themselves only
 *
 * with three conditions binding every row:
 *   1. the ACTOR must already hold ordinary Location authorization for the
 *      path they are using — established by CalendarLocationResolver before
 *      anything here runs, so owner authority over staff is never authority
 *      over a Location the owner cannot reach;
 *   2. the TARGET's eligibility is re-derived at WRITE time through
 *      LocationAccessGuard, so availability can never be written for someone
 *      who is not currently eligible there; and
 *   3. `staff_time_off` is User-global — an Owner writing time off removes
 *      that person from EVERY Location they are granted, not only the one
 *      the Owner reached them through. Nothing Location-shaped is persisted
 *      on the row; the Location is the path to authority, never a scope.
 *
 * Note what "Owner" means here, and why it is not a role lookup: §6 names
 * `Workspace.owner_user_id` and `Business.customer_id`, the two branches
 * LocationAccessGuard itself already checks ahead of any membership. There
 * is no `WorkspaceMembershipRole::Owner` case, and an Admin membership is
 * deliberately NOT owner authority in V1.
 */
class StaffAvailabilityService
{
    public function __construct(private readonly CalendarLocationResolver $locations)
    {
    }

    public function isOwner(int $actorUserId, Workspace $workspace, Business $business): bool
    {
        return (int) $workspace->owner_user_id === $actorUserId
            || (int) $business->customer_id === $actorUserId;
    }

    /**
     * The authority table, as a single predicate. Condition 2 is applied to
     * EVERY row including self-management: the actor already passed the
     * Location guard, so their own eligibility holds by construction, and
     * checking it uniformly keeps the rule literal rather than implied.
     */
    public function mayManageAvailabilityFor(
        int $actorUserId,
        int $targetStaffUserId,
        Workspace $workspace,
        Business $business,
        BusinessLocation $location
    ): bool {
        if (! $this->locations->isEligible($targetStaffUserId, $location)) {
            return false;
        }

        if ($actorUserId === $targetStaffUserId) {
            return true;
        }

        return $this->isOwner($actorUserId, $workspace, $business);
    }

    /**
     * @throws StaffAvailabilityAuthorityException
     */
    public function assertMayManageAvailabilityFor(
        int $actorUserId,
        int $targetStaffUserId,
        Workspace $workspace,
        Business $business,
        BusinessLocation $location
    ): void {
        if (! $this->locations->isEligible($targetStaffUserId, $location)) {
            throw StaffAvailabilityAuthorityException::targetNotEligible($targetStaffUserId, (int) $location->id);
        }

        if ($actorUserId === $targetStaffUserId) {
            return;
        }

        if (! $this->isOwner($actorUserId, $workspace, $business)) {
            throw StaffAvailabilityAuthorityException::notSelf($actorUserId, $targetStaffUserId);
        }
    }

    // --- recurring rules (§5.2, Location-bound) ---

    /** @return Collection<int, StaffAvailabilityRule> */
    public function rulesForLocation(BusinessLocation $location): Collection
    {
        return StaffAvailabilityRule::query()
            ->where('business_location_id', $location->id)
            ->orderBy('staff_user_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Re-derived and Location-scoped, so a rule id belonging to a sibling
     * Location cannot be reached by supplying it. The caller turns null
     * into 404.
     */
    public function findRuleForLocation(BusinessLocation $location, int $ruleId): ?StaffAvailabilityRule
    {
        return StaffAvailabilityRule::query()
            ->where('business_location_id', $location->id)
            ->where('id', $ruleId)
            ->first();
    }

    /**
     * Multiple rows per (staff, Location, day) are legitimate — that is a
     * split shift (§5.2), so no uniqueness is imposed here.
     *
     * `start_time`/`end_time` are LOCAL time-of-day in the Location's
     * Business's timezone and are stored verbatim; nothing converts them to
     * a fixed UTC offset, because doing so at write time is exactly the DST
     * corruption §5.2 forbids.
     *
     * @throws StaffAvailabilityAuthorityException
     */
    public function createRule(
        Workspace $workspace,
        Business $business,
        BusinessLocation $location,
        int $targetStaffUserId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        int $actorUserId
    ): StaffAvailabilityRule {
        $this->assertMayManageAvailabilityFor($actorUserId, $targetStaffUserId, $workspace, $business, $location);

        return StaffAvailabilityRule::create([
            'business_location_id' => $location->id,
            'staff_user_id' => $targetStaffUserId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    /**
     * @throws StaffAvailabilityAuthorityException
     */
    public function deleteRule(
        Workspace $workspace,
        Business $business,
        BusinessLocation $location,
        StaffAvailabilityRule $rule,
        int $actorUserId
    ): void {
        $this->assertMayManageAvailabilityFor(
            $actorUserId,
            (int) $rule->staff_user_id,
            $workspace,
            $business,
            $location
        );

        $rule->delete();
    }

    // --- time off (§5.3, User-global) ---

    /**
     * Time off is a property of the staff member, not of a Location, so this
     * reads by User and never filters by Location. The surface says so
     * plainly (§6 condition 3).
     *
     * @param  array<int, int>  $staffUserIds
     * @return Collection<int, StaffTimeOff>
     */
    public function timeOffForStaff(array $staffUserIds): Collection
    {
        if ($staffUserIds === []) {
            return collect();
        }

        return StaffTimeOff::query()
            ->whereIn('staff_user_id', $staffUserIds)
            ->orderBy('start_at')
            ->get();
    }

    /**
     * A time-off row has no Location axis of its own (§5.3), so it cannot be
     * scoped by one the way a rule can. What CAN be scoped is reach: the row
     * is returned only when its staff member is currently eligible at the
     * Location the actor came in through.
     *
     * That keeps this slice's two refusals honest and distinct. A row
     * belonging to someone outside this Location's reach — another
     * Business's staff member, say — is simply NOT FOUND (404), because that
     * is a question about existence and reach. A 403 is reserved for the
     * authority table proper: a row the actor can genuinely see, for a
     * person they may not manage.
     */
    public function findTimeOffWithinReach(BusinessLocation $location, int $timeOffId): ?StaffTimeOff
    {
        $timeOff = StaffTimeOff::query()->where('id', $timeOffId)->first();

        if ($timeOff === null) {
            return null;
        }

        if (! $this->locations->isEligible((int) $timeOff->staff_user_id, $location)) {
            return null;
        }

        return $timeOff;
    }

    /**
     * NOTHING LOCATION-SHAPED IS WRITTEN (§5.3). The Location argument
     * exists solely to establish authority; the persisted row is
     * User-global, and this method must never grow a Location column.
     *
     * @throws StaffAvailabilityAuthorityException
     */
    public function createTimeOff(
        Workspace $workspace,
        Business $business,
        BusinessLocation $location,
        int $targetStaffUserId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?string $reason,
        int $actorUserId
    ): StaffTimeOff {
        $this->assertMayManageAvailabilityFor($actorUserId, $targetStaffUserId, $workspace, $business, $location);

        return StaffTimeOff::create([
            'staff_user_id' => $targetStaffUserId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'reason' => $reason,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /**
     * @throws StaffAvailabilityAuthorityException
     */
    public function deleteTimeOff(
        Workspace $workspace,
        Business $business,
        BusinessLocation $location,
        StaffTimeOff $timeOff,
        int $actorUserId
    ): void {
        $this->assertMayManageAvailabilityFor(
            $actorUserId,
            (int) $timeOff->staff_user_id,
            $workspace,
            $business,
            $location
        );

        $timeOff->delete();
    }
}
