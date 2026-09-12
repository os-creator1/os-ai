<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Entitlement\LastActiveLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\LocationSlotAllocationRequiredException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
use App\Exceptions\Entitlement\PrimaryLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ArchiveBusinessLocationRequest;
use App\Http\Requests\Business\StoreBusinessLocationRequest;
use App\Http\Requests\Business\UpsertBusinessLocationRequest;
use App\Library\Business\BusinessLocationManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Customer Experience Slice 1A — Settings → Business → Locations.
 *
 * A location is a storefront, branch or service area INSIDE this Business;
 * it is never another account. Every write delegates to
 * BusinessLocationManager (the canonical boundary, contract §7.3b); this
 * controller holds no capacity arithmetic and performs no location write.
 *
 * Tenancy: Workspace by uid → Business inside it → RFC-003
 * userCanAccessBusiness(); anything else is 404, never 403 (contract §5.4).
 * Changing locations additionally needs Workspace owner-or-active-Admin
 * authority, re-checked by the manager; everyone who can reach the Business
 * can read.
 *
 * There is deliberately no purchase action. A 4th or 5th location needs an
 * add-on allocation, and Core/Growth prices are not set, so the page
 * explains that plainly instead of offering checkout (money gate).
 */
class BusinessLocationsController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly BusinessLocationManager $locationManager,
        private readonly BusinessLocationRepository $locationRepository,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveBusiness($workspaceUid, $businessUid);

        $locations = $this->locationManager->locations($business);

        return view('customer.business.locations.index', [
            'workspace' => $workspace,
            'business' => $business,
            'activeLocations' => $locations->filter(fn (BusinessLocation $location) => $location->isActive())->values(),
            'archivedLocations' => $locations->filter(fn (BusinessLocation $location) => $location->isArchived())->values(),
            'capacity' => $this->locationManager->capacity($business),
            'canManage' => $this->locationManager->canManage((int) Auth::id(), $business),
            'planUrl' => $this->planUrl($workspace, $business),
        ]);
    }

    public function create(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->resolveBusiness($workspaceUid, $businessUid);

        if (! $this->locationManager->canManage((int) Auth::id(), $business)) {
            throw new AuthorizationException('Only the account owner or an account admin can add locations.');
        }

        $capacity = $this->locationManager->capacity($business);

        if (! $capacity->allowed) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal($capacity->denialReason, $business));
        }

        return view('customer.business.locations.create', [
            'workspace' => $workspace,
            'business' => $business,
            'capacity' => $capacity,
        ]);
    }

    public function store(StoreBusinessLocationRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);

        try {
            $location = $this->locationManager->createLocation($business, $request->validated(), (int) Auth::id());
        } catch (LocationSlotAllocationRequiredException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal('location_slot_allocation_required', $business));
        } catch (LocationSlotLimitExceededException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal('location_slot_limit_exceeded', $business));
        } catch (WorkspacePlanUnassignedException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal('workspace_plan_unassigned', $business));
        }

        return $this->toIndex($workspaceUid, $businessUid)->with('flash_success', "Added {$location->name}.");
    }

    public function edit(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $location = $this->resolveLocation($business, $locationUid);

        if (! $this->locationManager->canManage((int) Auth::id(), $business)) {
            throw new AuthorizationException('Only the account owner or an account admin can edit locations.');
        }

        return view('customer.business.locations.edit', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
        ]);
    }

    public function update(UpsertBusinessLocationRequest $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $location = $this->resolveLocation($business, $locationUid);

        $updated = $this->locationManager->updateLocation($location, $request->validated(), (int) Auth::id());

        return $this->toIndex($workspaceUid, $businessUid)->with('flash_success', "Saved {$updated->name}.");
    }

    public function archive(ArchiveBusinessLocationRequest $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $location = $this->resolveLocation($business, $locationUid);

        if ($location->isArchived()) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_info', "No change — {$location->name} is already archived.");
        }

        $newPrimary = null;
        $newPrimaryUid = $request->validated('new_primary_location_uid');

        if ($newPrimaryUid !== null && $newPrimaryUid !== '') {
            $newPrimary = $this->resolveLocation($business, $newPrimaryUid);
        }

        try {
            $archived = $this->locationManager->archiveLocation($location, (int) Auth::id(), $newPrimary);
        } catch (LastActiveLocationCannotBeArchivedException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', 'A business needs at least one active location, so this one can\'t be archived.');
        } catch (PrimaryLocationCannotBeArchivedException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', 'Choose which location becomes your primary location before archiving this one.');
        } catch (InvalidArgumentException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', 'The new primary location must be another active location of this business.');
        }

        return $this->toIndex($workspaceUid, $businessUid)->with('flash_success', "Archived {$archived->name}. Its details and history are kept, and you can reactivate it later.");
    }

    public function reactivate(string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $location = $this->resolveLocation($business, $locationUid);

        if ($location->isActive()) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_info', "No change — {$location->name} is already active.");
        }

        try {
            $reactivated = $this->locationManager->reactivateLocation($location, (int) Auth::id());
        } catch (LocationSlotAllocationRequiredException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal('location_slot_allocation_required', $business));
        } catch (LocationSlotLimitExceededException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal('location_slot_limit_exceeded', $business));
        } catch (WorkspacePlanUnassignedException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', $this->capacityRefusal('workspace_plan_unassigned', $business));
        }

        return $this->toIndex($workspaceUid, $businessUid)->with('flash_success', "Reactivated {$reactivated->name}.");
    }

    public function makePrimary(string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $location = $this->resolveLocation($business, $locationUid);

        if ($location->is_primary) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_info', "No change — {$location->name} is already your primary location.");
        }

        try {
            $primary = $this->locationManager->makePrimary($location, (int) Auth::id());
        } catch (InvalidArgumentException) {
            return $this->toIndex($workspaceUid, $businessUid)->with('flash_error', 'Only an active location can be your primary location.');
        }

        return $this->toIndex($workspaceUid, $businessUid)->with('flash_success', "{$primary->name} is now your primary location.");
    }

    /**
     * Customer copy for a refused activation — outcome terms only, never a
     * denial key, price or internal concept (redesign §6 principles 8, 12).
     */
    private function capacityRefusal(?string $reason, Business $business): string
    {
        $maximum = $this->locationManager->capacity($business)->maximumSlots;

        return match ($reason) {
            'location_slot_allocation_required' => 'You\'re using every location your plan currently includes. More locations are an add-on that can\'t be bought online yet — contact us to add one, or archive a location you no longer use.',
            'location_slot_limit_exceeded' => 'Your plan includes up to ' . ($maximum ?? 5) . ' active locations per business. To run more, move to the Agency plan, which includes unlimited locations.',
            default => 'New locations can\'t be added until this account has a plan.',
        };
    }

    /**
     * The upgrade/contact destination: the account page that shows the
     * plan, offered only to the account owner, who can always reach it.
     */
    private function planUrl(Workspace $workspace, Business $business): ?string
    {
        if ((int) $workspace->owner_user_id !== (int) Auth::id()) {
            return null;
        }

        return route('customer.workspaces.show', [$workspace->uid]);
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        return [$workspace, $business];
    }

    private function resolveLocation(Business $business, string $locationUid): BusinessLocation
    {
        $location = $this->locationRepository->findForBusinessByUid($business, $locationUid);

        if ($location === null) {
            abort(404);
        }

        return $location;
    }

    private function toIndex(string $workspaceUid, string $businessUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.locations.index', [$workspaceUid, $businessUid]);
    }
}
