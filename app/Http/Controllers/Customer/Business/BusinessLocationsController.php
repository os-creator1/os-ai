<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Exceptions\Entitlement\LastActiveLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\LocationAllocationCancellationRefusedException;
use App\Exceptions\Entitlement\LocationSlotAllocationRequiredException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
use App\Exceptions\Entitlement\PrimaryLocationCannotBeArchivedException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Business\ArchiveBusinessLocationRequest;
use App\Http\Requests\Business\StoreBusinessLocationRequest;
use App\Http\Requests\Business\UpsertBusinessLocationRequest;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Customer Experience Slice 1A — "Physical locations & service areas",
 * under Business Settings.
 *
 * A location is a physical branch, storefront, office or service area
 * INSIDE one Business. It is never another account, never a Workspace, and
 * never an account-switcher entry. The copy in the views says so in plain
 * language and never exposes catalog keys, counters, transition names or
 * database terminology.
 *
 * Every action resolves its Business through the exact RFC-003 §14.1
 * boundary, mirroring GoogleBusinessProfileController: Workspace by uid →
 * Business inside that Workspace → userCanAccessBusiness() → active
 * Business. Every tenancy failure is 404, never 403, so a foreign
 * identifier is indistinguishable from an unknown one.
 *
 * Every count-increasing write delegates to BusinessLocationManager, the
 * one canonical capacity boundary (contract §7.3b). This controller
 * performs no BusinessLocation write of its own.
 *
 * Slice 1A COLLECTS NOTHING, and therefore SELLS nothing: there is no
 * customer action here that adds or removes a paid additional location
 * slot. Correction round 1 removed those, because with no Core/Growth
 * price and no billing path they would have granted nominally paid
 * capacity for free while claiming it would appear on a bill.
 */
class BusinessLocationsController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly BusinessLocationManager $locations,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    public function overview(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        [, $business] = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        return view('customer.business.locations.index', $this->pageData($workspaceUid, $businessUid, $business));
    }

    public function store(StoreBusinessLocationRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveManageableBusiness($workspaceUid, $businessUid);

        try {
            $this->locations->createLocation($business, $request->validated());
        } catch (LocationSlotAllocationRequiredException) {
            return $this->back($workspaceUid, $businessUid, 'error', 'All the locations your plan covers are already open. Close one you no longer operate from to open a different one — extra locations cannot be added yet.');
        } catch (LocationSlotLimitExceededException) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Your current plan covers up to 5 locations. Move to the Agency plan to add more.');
        }

        return $this->back($workspaceUid, $businessUid, 'success', 'Location added.');
    }

    /**
     * Edit an existing location's details. Not count-increasing, so no
     * capacity assertion applies — but it still goes through the canonical
     * boundary so every location write has one owner.
     */
    public function update(UpsertBusinessLocationRequest $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveManageableBusiness($workspaceUid, $businessUid);

        $location = $this->locations->findLocation($business, $locationUid);

        abort_unless($location !== null, 404);

        $this->locations->updateLocation($business, $location, $request->validated());

        return $this->back($workspaceUid, $businessUid, 'success', 'Location details saved.');
    }

    public function archive(ArchiveBusinessLocationRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveManageableBusiness($workspaceUid, $businessUid);

        $validated = $request->validated();
        $location = $this->locations->findLocation($business, $validated['location_uid']);

        abort_unless($location !== null, 404);

        try {
            $this->locations->archiveLocation($business, $location, $validated['new_primary_uid'] ?? null);
        } catch (PrimaryLocationCannotBeArchivedException) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Choose which other open location becomes the main one before closing this one.');
        } catch (LastActiveLocationCannotBeArchivedException) {
            return $this->back($workspaceUid, $businessUid, 'error', 'A business needs at least one open location.');
        }

        return $this->back($workspaceUid, $businessUid, 'success', 'Location closed. Its history is kept, and it no longer uses one of your location slots.');
    }

    public function reactivate(ArchiveBusinessLocationRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveManageableBusiness($workspaceUid, $businessUid);

        $location = $this->locations->findLocation($business, $request->validated()['location_uid']);

        abort_unless($location !== null, 404);

        try {
            $this->locations->reactivateLocation($business, $location);
        } catch (LocationSlotAllocationRequiredException) {
            return $this->back($workspaceUid, $businessUid, 'error', 'All the locations your plan covers are already open. Close another one first — extra locations cannot be added yet.');
        } catch (LocationSlotLimitExceededException) {
            return $this->back($workspaceUid, $businessUid, 'error', 'Your current plan covers up to 5 open locations. Move to the Agency plan to reopen this one.');
        }

        return $this->back($workspaceUid, $businessUid, 'success', 'Location reopened.');
    }

    /*
     * THERE IS DELIBERATELY NO CUSTOMER ALLOCATION ACTION HERE.
     *
     * Correction round 1. A paid 4th/5th-location allocation is a
     * SUBSCRIPTION AMENDMENT. Slice 1A has no Core/Growth price (both are
     * still null in workspace_plan_catalog), so the contracted 50%-of-plan
     * amount cannot even be calculated, and there is no checkout,
     * subscription amendment, invoice item, payment evidence or billing
     * integration behind it.
     *
     * A customer-callable route that incremented additional_location_slots
     * would therefore have granted nominally paid capacity for free, and
     * the copy that said it "appears on your next invoice" was an
     * unsupported billing promise. Both are removed: the routes no longer
     * exist, the view renders a non-actionable explanation instead of a
     * form, and the domain seam itself now refuses any caller that cannot
     * present verified billing or operator provenance
     * (EntitlementManager::allocateAdditionalLocationSlot()).
     *
     * The additive schema, the stored 0.5000 ratio and the capacity
     * decision all remain, so the future billing slice has a real seam to
     * call. Nothing here invents a price, an invoice, a deferred charge, a
     * payment state or a fake checkout.
     */

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function pageData(string $workspaceUid, string $businessUid, Business $business): array
    {
        $capacity = $this->locations->capacityFor($business);
        $all = $this->locations->locationsFor($business);

        return [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'capacity' => $capacity,
            'activeLocations' => $all->filter(fn ($location) => $location->isActive())->values(),
            'archivedLocations' => $all->filter(fn ($location) => $location->isArchived())->values(),
        ];
    }

    /**
     * RFC-003 §14.1 — the mandatory chain. Every failure is 404.
     *
     * @return array{0: Workspace, 1: Business}
     */
    /**
     * The same chain, plus the Workspace owner-or-active-Admin authority
     * every other Business-level entitlement mutation already requires
     * (EntitlementManager::assertBusinessLocationManagementAuthority).
     *
     * An ordinary member with Business access may VIEW locations but may
     * not create, archive, reactivate or edit. The denial is a
     * 403 from the shared authorization layer, distinct from the 404 a
     * foreign or unknown identifier produces, because by this point the
     * actor has already legitimately proven access to this Business.
     *
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveManageableBusiness(string $workspaceUid, string $businessUid): array
    {
        [$workspace, $business] = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        try {
            $this->entitlements->assertBusinessLocationManagementAuthority($business, (int) Auth::id());
        } catch (UnauthorizedWorkspaceManagementException) {
            // Deliberately an AuthorizationException rather than abort(403):
            // this platform's App\Exceptions\Handler renders EVERY
            // HttpException as a 404 outside the `local` environment, so an
            // abort(403) would be indistinguishable from "no such Business".
            // AuthorizationException is the platform's established
            // permission-denial path and renders 401, exactly as every other
            // customer permission denial does.
            throw new AuthorizationException('You are not authorized to manage this business\'s locations.');
        }

        return [$workspace, $business];
    }

    private function resolveAccessibleBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        return [$workspace, $business];
    }

    private function back(string $workspaceUid, string $businessUid, string $status, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.locations.index', [$workspaceUid, $businessUid])
            ->with(['status' => $status, 'message' => $message]);
    }
}
