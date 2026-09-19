<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 15 §5.1, §6, §12.B — authenticated Booking Type
 * CRUD and the staff-pool assignment, at one Location.
 *
 * THREE GATES ON EVERY ACTION, in this order, none of them optional:
 *   1. Workspace/Business tenancy — the canonical
 *      BusinessRouteAccess::actorMayUseBusinessRoute() chain;
 *   2. the Calendar entitlement decision, via
 *      resolveEntitledBusinessTenancy(..., PlatformFeature::Calendar->value);
 *   3. LocationAccessGuard for the exact Location, via
 *      CalendarLocationResolver.
 *
 * Gate 2 is why every route here currently 404s even for an account owner
 * with a valid Location: `PlatformFeature::Calendar` is still `Planned`
 * (§11), so EntitlementManager refuses with `platform_feature_unavailable`
 * before any database read. That is the intended end state of this
 * sub-slice, not a defect — Sub-slice E's flip is what makes these surfaces
 * executable, and nothing here may flip it early.
 *
 * NO booking engine, no appointment lifecycle, no round-robin execution, no
 * calendar grid: Sub-slices C and D own those.
 */
class BookingTypesController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly BookingTypeManager $bookingTypes,
        private readonly CalendarLocationResolver $locations,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        return view('customer.business.calendar.booking-types.index', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'bookingTypes' => $this->bookingTypes->forLocation($location),
        ]);
    }

    public function create(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        return view('customer.business.calendar.booking-types.create', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $bookingType = $this->bookingTypes->create($location, $this->validated($request), (int) Auth::id());

        return redirect()
            ->route('customer.workspaces.businesses.calendar.booking-types.edit', [
                $workspaceUid, $businessUid, $locationUid, $bookingType->uid,
            ])
            ->with('flash_success', 'Booking type created.');
    }

    public function edit(string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        return view('customer.business.calendar.booking-types.edit', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'bookingType' => $bookingType,
            'configuredStaff' => $this->bookingTypes->configuredStaffWithEligibility($bookingType),
            'eligibleStaff' => $this->locations->eligibleStaff($workspace, $business, $location),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        $this->bookingTypes->update($bookingType, $this->validated($request));

        return $this->backToEdit($workspaceUid, $businessUid, $locationUid, $bookingTypeUid)
            ->with('flash_success', 'Booking type updated.');
    }

    public function toggleActive(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $this->bookingTypes->setActive($bookingType, (bool) $validated['is_active']);

        return $this->backToEdit($workspaceUid, $businessUid, $locationUid, $bookingTypeUid)
            ->with('flash_success', $validated['is_active'] ? 'Booking type activated.' : 'Booking type deactivated.');
    }

    /**
     * §5.1/§6 — configuration intent, never authorization. Every submitted
     * candidate is re-derived through LocationAccessGuard by the manager; an
     * ineligible id is refused and named back to the actor rather than
     * silently written or silently dropped.
     */
    public function syncStaff(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        // `nullable` on the members, not just `integer`: the form always
        // submits one empty hidden entry so that clearing every checkbox
        // still sends the key at all (otherwise an all-unchecked save would
        // look like "no change" rather than "remove everyone"). The manager
        // discards non-positive ids, so the placeholder never reaches a write.
        $validated = $request->validate([
            'staff_user_ids' => ['present', 'array'],
            'staff_user_ids.*' => ['nullable', 'integer'],
        ]);

        $result = $this->bookingTypes->syncStaff($bookingType, $validated['staff_user_ids']);

        $redirect = $this->backToEdit($workspaceUid, $businessUid, $locationUid, $bookingTypeUid);

        if ($result['refused'] !== []) {
            return $redirect->with(
                'flash_error',
                'Some staff members were not added: they are not currently authorized for this location.'
            );
        }

        return $redirect->with('flash_success', 'Booking type staff updated.');
    }

    /**
     * Gates 1 and 2 (tenancy + Calendar entitlement), then gate 3 (the exact
     * Location). A Location that belongs to another Business, or one this
     * actor cannot reach, is indistinguishable from one that does not exist.
     *
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

    private function bookingType(BusinessLocation $location, string $bookingTypeUid): BookingType
    {
        return $this->bookingTypes->findForLocation($location, $bookingTypeUid) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'color' => ['nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function backToEdit(string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.calendar.booking-types.edit', [
            $workspaceUid, $businessUid, $locationUid, $bookingTypeUid,
        ]);
    }
}
