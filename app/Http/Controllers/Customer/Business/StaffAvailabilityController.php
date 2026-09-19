<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Calendar\StaffAvailabilityAuthorityException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\StaffAvailabilityService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 15 §5.2, §5.3, §6, §12.B — recurring availability
 * windows and User-global time off.
 *
 * SAME THREE GATES as every other authenticated Calendar route (tenancy →
 * Calendar entitlement → LocationAccessGuard), and then a FOURTH that is
 * unique to this surface: §6's availability-authority table, applied per
 * target staff member and re-derived at write time by
 * StaffAvailabilityService.
 *
 * TWO DIFFERENT REFUSALS, deliberately distinguished:
 *   - 404 for anything about existence or reach — an unentitled feature, a
 *     Location this actor cannot reach, a Location belonging to another
 *     Business, a rule id from a sibling Location. The actor must not learn
 *     the resource exists.
 *   - 403 for the authority table itself — the actor legitimately reached
 *     this Location and surface but may not write THIS target's
 *     availability. Mirrors BusinessLocationsController's own precedent for
 *     a role refusal on a resource the actor can see.
 *
 * TIME OFF IS USER-GLOBAL (§5.3). The Location in the route establishes
 * authority and nothing else: no Location is written to `staff_time_off`,
 * and the surface says so plainly, because time off removes that person
 * from every Location they are granted rather than only this one.
 */
class StaffAvailabilityController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly StaffAvailabilityService $availability,
        private readonly CalendarLocationResolver $locations,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $actorId = (int) Auth::id();
        $eligibleStaff = $this->locations->eligibleStaff($workspace, $business, $location);

        return view('customer.business.calendar.availability.index', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'rules' => $this->availability->rulesForLocation($location),
            'timeOff' => $this->availability->timeOffForStaff(
                $eligibleStaff->pluck('id')->map(static fn ($id) => (int) $id)->all()
            ),
            'eligibleStaff' => $eligibleStaff,
            'actorId' => $actorId,
            // Presentation only: the same predicate the service enforces, so
            // the surface offers no control the write path would refuse.
            'isOwner' => $this->availability->isOwner($actorId, $workspace, $business),
        ]);
    }

    public function storeRule(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        $this->attempt(fn () => $this->availability->createRule(
            $workspace,
            $business,
            $location,
            (int) $validated['staff_user_id'],
            (int) $validated['day_of_week'],
            $validated['start_time'] . ':00',
            $validated['end_time'] . ':00',
            (int) Auth::id()
        ));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Availability window added.');
    }

    public function destroyRule(string $workspaceUid, string $businessUid, string $locationUid, int $ruleId): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $rule = $this->availability->findRuleForLocation($location, $ruleId) ?? abort(404);

        $this->attempt(fn () => $this->availability->deleteRule($workspace, $business, $location, $rule, (int) Auth::id()));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Availability window removed.');
    }

    public function storeTimeOff(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attempt(fn () => $this->availability->createTimeOff(
            $workspace,
            $business,
            $location,
            (int) $validated['staff_user_id'],
            Carbon::parse($validated['start_at']),
            Carbon::parse($validated['end_at']),
            $validated['reason'] ?? null,
            (int) Auth::id()
        ));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Time off added. It applies at every location this person works.');
    }

    public function destroyTimeOff(string $workspaceUid, string $businessUid, string $locationUid, int $timeOffId): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $timeOff = $this->availability->findTimeOffWithinReach($location, $timeOffId) ?? abort(404);

        $this->attempt(fn () => $this->availability->deleteTimeOff($workspace, $business, $location, $timeOff, (int) Auth::id()));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Time off removed.');
    }

    /**
     * @return array{0: Workspace, 1: Business, 2: BusinessLocation}
     */
    private function scope(string $workspaceUid, string $businessUid, string $locationUid): array
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy(
            $workspaceUid,
            $businessUid,
            PlatformFeature::Calendar->value
        );

        $location = $this->locations->resolveForActor($business, $locationUid, (int) Auth::id());

        if ($location === null) {
            abort(404);
        }

        return [$workspace, $business, $location];
    }

    /**
     * §6's authority table refused this write. The resource is legitimately
     * visible to this actor, so this is a 403 and not a 404 — see the class
     * docblock for why the two are kept apart.
     */
    private function attempt(callable $write): void
    {
        try {
            $write();
        } catch (StaffAvailabilityAuthorityException $exception) {
            throw new AuthorizationException($exception->getMessage());
        }
    }

    private function back(string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.calendar.availability.index', [
            $workspaceUid, $businessUid, $locationUid,
        ]);
    }
}
