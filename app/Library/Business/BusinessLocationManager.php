<?php

namespace App\Library\Business;

use App\DTO\Entitlement\LocationSlotCapacityDecision;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Entitlement\LastActiveLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\PrimaryLocationCannotBeArchivedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Customer Experience Slice 1A — the ONE canonical service boundary for a
 * Business's physical locations (contract §7.3b).
 *
 * Every customer-reachable operation that can raise a Business's ACTIVE
 * location count — creating a location and reactivating an archived one —
 * runs here: one transaction, the Business row locked first, capacity
 * asserted by EntitlementManager against that locked row, then the write.
 * Onboarding's first-location step reaches the same boundary through
 * BusinessManager::upsertPrimaryLocation(). The repository methods that can
 * add an active location have no production caller outside this class
 * (asserted by the source-boundary inventory test, T-LOC-9 — which guards
 * the architecture; it cannot prove no future code writes to the table).
 *
 * A location is a physical storefront, branch or service area INSIDE one
 * Business — never an account, tenancy, payer, wallet or authorization
 * boundary. Archiving is a state change, never a delete: the row, its
 * Google binding, analytics and audit history all stay.
 *
 * Capacity arithmetic lives only in EntitlementManager; this class never
 * re-derives it.
 */
final class BusinessLocationManager
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessLocationRepository $locationRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceManager $workspaceManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
    ) {
    }

    public function capacity(Business $business): LocationSlotCapacityDecision
    {
        return $this->entitlementManager->decideLocationSlotCapacity($business);
    }

    public function locations(Business $business): Collection
    {
        return $this->locationRepository->forBusiness($business);
    }

    /**
     * Whether the actor may change this Business's locations: RFC-003
     * access to the Business AND Workspace owner-or-active-Admin authority —
     * the same authority every other Business-level entitlement mutation
     * uses. Restricted and staff members read; they do not change.
     */
    public function canManage(int $actorUserId, Business $business): bool
    {
        if (! $this->workspaceManager->userCanAccessBusiness($actorUserId, $business)) {
            return false;
        }

        $workspace = $this->workspaceRepository->findById((int) $business->workspace_id);

        if ($workspace === null) {
            return false;
        }

        if ((int) $workspace->owner_user_id === $actorUserId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorUserId);

        return $membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin;
    }

    /** Contract §7.3 — a new active location, capacity-asserted under the Business lock. */
    public function createLocation(Business $business, array $attributes, int $actorUserId): BusinessLocation
    {
        return DB::transaction(function () use ($business, $attributes, $actorUserId) {
            $lockedBusiness = $this->lockBusiness($business);
            $this->assertCanManage($actorUserId, $lockedBusiness);

            $this->entitlementManager->assertCanActivateAnotherLocation($lockedBusiness);

            $location = $this->locationRepository->createForBusiness($lockedBusiness, $attributes);

            if ($this->locationRepository->findPrimary($lockedBusiness) === null) {
                $location = $this->locationRepository->setPrimary($location);
            }

            return $location->refresh();
        });
    }

    /** Details only — never ownership, primary flag or lifecycle, so it consumes no capacity. */
    public function updateLocation(BusinessLocation $location, array $attributes, int $actorUserId): BusinessLocation
    {
        return DB::transaction(function () use ($location, $attributes, $actorUserId) {
            $lockedBusiness = $this->lockBusinessOf($location);
            $this->assertCanManage($actorUserId, $lockedBusiness);
            $lockedLocation = $this->lockLocationOf($lockedBusiness, $location);

            return $this->locationRepository->updateDetails($lockedLocation, $attributes);
        });
    }

    /**
     * Contract §7.3a — frees one active slot and keeps everything. The
     * primary location is archived only together with naming another
     * active location as primary, in this one transaction; the last active
     * location is never archived. A paid additional allocation is NOT
     * cancelled (rules 4–5); complimentary grandfathered excess IS consumed
     * (§7.5.3). Archiving an already-archived location changes nothing.
     */
    public function archiveLocation(BusinessLocation $location, int $actorUserId, ?BusinessLocation $newPrimary = null): BusinessLocation
    {
        return DB::transaction(function () use ($location, $actorUserId, $newPrimary) {
            $lockedBusiness = $this->lockBusinessOf($location);
            $this->assertCanManage($actorUserId, $lockedBusiness);
            $lockedLocation = $this->lockLocationOf($lockedBusiness, $location);

            if ($lockedLocation->isArchived()) {
                return $lockedLocation;
            }

            if ($this->locationRepository->countActiveForUpdate($lockedBusiness->id) <= 1) {
                throw new LastActiveLocationCannotBeArchivedException($lockedBusiness->id);
            }

            if ($lockedLocation->is_primary) {
                if ($newPrimary === null) {
                    throw new PrimaryLocationCannotBeArchivedException($lockedLocation->id);
                }

                $lockedNewPrimary = $this->lockLocationOf($lockedBusiness, $newPrimary);

                if ($lockedNewPrimary->id === $lockedLocation->id || ! $lockedNewPrimary->isActive()) {
                    throw new InvalidArgumentException('The new primary location must be another active location of the same Business.');
                }

                $this->locationRepository->setPrimary($lockedNewPrimary);
                $lockedLocation->refresh();
            }

            $archived = $this->locationRepository->archive($lockedLocation);

            $this->entitlementManager->reconcileGrandfatheredLocationsAfterArchive($lockedBusiness->refresh(), $actorUserId);

            return $archived;
        });
    }

    /**
     * Contract §7.3a rule 7 — reactivation runs exactly the capacity check
     * creation runs. Reactivating an active location changes nothing.
     */
    public function reactivateLocation(BusinessLocation $location, int $actorUserId): BusinessLocation
    {
        return DB::transaction(function () use ($location, $actorUserId) {
            $lockedBusiness = $this->lockBusinessOf($location);
            $this->assertCanManage($actorUserId, $lockedBusiness);
            $lockedLocation = $this->lockLocationOf($lockedBusiness, $location);

            if ($lockedLocation->isActive()) {
                return $lockedLocation;
            }

            $this->entitlementManager->assertCanActivateAnotherLocation($lockedBusiness);

            $reactivated = $this->locationRepository->reactivate($lockedLocation);

            if ($this->locationRepository->findPrimary($lockedBusiness) === null) {
                $reactivated = $this->locationRepository->setPrimary($reactivated);
            }

            return $reactivated->refresh();
        });
    }

    /** Moves the primary flag to another ACTIVE location. Consumes no capacity. */
    public function makePrimary(BusinessLocation $location, int $actorUserId): BusinessLocation
    {
        return DB::transaction(function () use ($location, $actorUserId) {
            $lockedBusiness = $this->lockBusinessOf($location);
            $this->assertCanManage($actorUserId, $lockedBusiness);
            $lockedLocation = $this->lockLocationOf($lockedBusiness, $location);

            if (! $lockedLocation->isActive()) {
                throw new InvalidArgumentException('Only an active location can be the primary location.');
            }

            if ($lockedLocation->is_primary) {
                return $lockedLocation;
            }

            return $this->locationRepository->setPrimary($lockedLocation)->refresh();
        });
    }

    /**
     * Onboarding's location step, reached through
     * BusinessManager::upsertPrimaryLocation(), which has already asserted
     * the customer owns this Business. No new authority requirement is added
     * to that legacy path (the RFC-004 §17.3 posture). It edits the primary
     * location, or — only when the Business has none — creates it, asserting
     * capacity first like every other activation. A first location is always
     * inside the three included, so onboarding behaves exactly as before.
     */
    public function upsertPrimaryLocation(Business $business, array $attributes): BusinessLocation
    {
        return DB::transaction(function () use ($business, $attributes) {
            $lockedBusiness = $this->lockBusiness($business);

            if ($this->locationRepository->findPrimary($lockedBusiness) === null) {
                $this->entitlementManager->assertCanActivateAnotherLocation($lockedBusiness);
            }

            return $this->locationRepository->upsertPrimary($lockedBusiness, $attributes);
        });
    }

    private function assertCanManage(int $actorUserId, Business $lockedBusiness): void
    {
        if (! $this->canManage($actorUserId, $lockedBusiness)) {
            throw new AuthorizationException('Only the account owner or an account admin can change this business\'s locations.');
        }
    }

    private function lockBusiness(Business $business): Business
    {
        $locked = $this->businessRepository->findForUpdate($business->id);

        if ($locked === null) {
            throw new WorkspaceBusinessNotFoundException($business->id);
        }

        return $locked;
    }

    private function lockBusinessOf(BusinessLocation $location): Business
    {
        $locked = $this->businessRepository->findForUpdate((int) $location->business_id);

        if ($locked === null) {
            throw new WorkspaceBusinessNotFoundException((int) $location->business_id);
        }

        return $locked;
    }

    /** Re-reads the location under lock and proves it still belongs to the locked Business. */
    private function lockLocationOf(Business $lockedBusiness, BusinessLocation $location): BusinessLocation
    {
        $locked = $this->locationRepository->findForUpdate($location->id);

        if ($locked === null || (int) $locked->business_id !== (int) $lockedBusiness->id) {
            throw new InvalidArgumentException('That location does not belong to this Business.');
        }

        return $locked;
    }
}
