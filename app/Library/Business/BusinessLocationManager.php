<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Exceptions\Entitlement\LastActiveLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\PrimaryLocationCannotBeArchivedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\LocationSlotCapacityDecision;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Slice 1A — THE ONE CANONICAL PHYSICAL-LOCATION
 * BOUNDARY (contract §7.3b).
 *
 * A `BusinessLocation` is only a physical branch, storefront, office or
 * service area inside one Business. It is never a tenancy, payer, wallet,
 * authorization or account-switcher boundary, and nothing here treats it
 * as one.
 *
 * EVERY customer-reachable operation that increases a Business's ACTIVE
 * location count — create AND reactivate — goes through this class. Each
 * one:
 *
 *   1. opens a transaction;
 *   2. takes the Business ROW LOCK (`findForUpdate`), which is the
 *      serialization point, so two concurrent requests cannot both claim
 *      the final slot;
 *   3. re-reads capacity from the locked row;
 *   4. asserts capacity BEFORE the count-increasing write;
 *   5. writes.
 *
 * A preflight count followed by an unlocked insert would be a race, and is
 * deliberately not what happens here.
 *
 * This class deliberately does NOT install a model observer. An observer
 * would fire inside factories, seeders, migrations and administrative
 * maintenance, creating hidden side effects far from this boundary
 * (contract §7.3b point 3 permits those paths to write directly).
 * Enforcement lives at this explicit seam instead, guarded by the
 * source-boundary inventory test (T-LOC-9).
 *
 * Slice 1A COLLECTS NOTHING. Allocations record capacity only; no Stripe,
 * wallet, provider or refund path is touched (§9 exclusions).
 */
final class BusinessLocationManager
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessLocationRepository $locationRepository,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * Create one ACTIVE physical location, under the Business row lock,
     * after asserting capacity (contract §7.3).
     *
     * The first location of a Business becomes primary automatically —
     * a Business must always have exactly one primary among its active
     * locations. Later locations never silently steal primary status.
     */
    public function createLocation(Business $business, array $attributes): BusinessLocation
    {
        return DB::transaction(function () use ($business, $attributes) {
            $locked = $this->lockBusiness($business);

            $this->entitlements->assertCanActivateAnotherLocation($locked);

            $isFirstActive = $this->locationRepository->countActiveForBusiness($locked) === 0;

            $location = $this->locationRepository->createActive($locked, $attributes);

            if ($isFirstActive) {
                $this->locationRepository->setPrimary($location);
                $location->refresh();

                BusinessPrimaryLocationUpdated::dispatch((int) $locked->id, (int) $location->id);
            }

            return $location;
        });
    }

    /**
     * Archive a location (contract §7.3a rules 2, 3, 8, 9).
     *
     * Archiving is a STATE CHANGE, never a delete: the row, its Google
     * Business Profile binding, analytics, website references and audit
     * history all survive untouched. Deleting instead would cascade
     * `business_google_locations` away through
     * `bgl_location_business_foreign`.
     *
     * Archiving frees exactly one active-location slot immediately, and
     * consumes complimentary grandfathered excess first (§7.5.3), so that
     * allowance never becomes a transferable free slot. Paid allocations
     * are never decremented here — that is the whole difference between
     * the two, and it is why a replacement location can reuse a paid slot
     * without re-purchase.
     *
     * A paid allocation is NOT cancelled automatically (§7.3a rule 5).
     *
     * @param  string|null  $newPrimaryUid  uid of the ACTIVE location that
     *                                      takes over primary status, when
     *                                      archiving the current primary.
     */
    public function archiveLocation(Business $business, BusinessLocation $location, ?string $newPrimaryUid = null): BusinessLocation
    {
        return DB::transaction(function () use ($business, $location, $newPrimaryUid) {
            $locked = $this->lockBusiness($business);

            $target = $this->locationRepository->findByUidForBusiness($locked, (string) $location->uid);

            if ($target === null) {
                throw new WorkspaceBusinessNotFoundException((int) $locked->id);
            }

            if ($target->isArchived()) {
                // Already archived — a true no-op, never a false success
                // audit row or a second capacity refund.
                return $target;
            }

            $activeCount = $this->locationRepository->countActiveForBusiness($locked);

            if ($activeCount <= 1) {
                throw new LastActiveLocationCannotBeArchivedException((int) $locked->id);
            }

            if ($target->is_primary) {
                // Primary status must be reassigned to another ACTIVE
                // location in this same transaction (§7.3a rule 9).
                $replacement = $newPrimaryUid === null
                    ? null
                    : $this->locationRepository->findByUidForBusiness($locked, $newPrimaryUid);

                if ($replacement === null || ! $replacement->isActive() || (int) $replacement->id === (int) $target->id) {
                    throw new PrimaryLocationCannotBeArchivedException((int) $locked->id, (int) $target->id);
                }

                $this->locationRepository->setPrimary($replacement);
                $target->refresh();

                BusinessPrimaryLocationUpdated::dispatch((int) $locked->id, (int) $replacement->id);
            }

            $target->forceFill([
                'lifecycle_state' => BusinessLocationLifecycleState::Archived,
                'archived_at' => now(),
            ])->save();

            $this->consumeGrandfatheredExcess($locked);

            return $target->refresh();
        });
    }

    /**
     * Reactivate an archived location (contract §7.3a rule 7).
     *
     * Runs the FULL capacity check under the same Business row lock —
     * including allocation requirements and the 6+ ceiling — because
     * reactivation increases the active count exactly as creation does.
     *
     * Fails without mutation when capacity is exhausted, and never creates
     * a new row or destroys the location's historical identity.
     */
    public function reactivateLocation(Business $business, BusinessLocation $location): BusinessLocation
    {
        return DB::transaction(function () use ($business, $location) {
            $locked = $this->lockBusiness($business);

            $target = $this->locationRepository->findByUidForBusiness($locked, (string) $location->uid);

            if ($target === null) {
                throw new WorkspaceBusinessNotFoundException((int) $locked->id);
            }

            if ($target->isActive()) {
                return $target;
            }

            $this->entitlements->assertCanActivateAnotherLocation($locked);

            $target->forceFill([
                'lifecycle_state' => BusinessLocationLifecycleState::Active,
                'archived_at' => null,
            ])->save();

            return $target->refresh();
        });
    }

    /**
     * Edit an existing location's details.
     *
     * Deliberately NOT capacity-asserted: editing changes no count, so it
     * is not an active-location-count-increasing operation. It still runs
     * through this boundary so that every customer-reachable location
     * write has exactly one owner, and it refuses to touch the lifecycle
     * state or primary flag — those move only through archive/reactivate
     * and their own explicit reassignment.
     */
    public function updateLocation(Business $business, BusinessLocation $location, array $attributes): BusinessLocation
    {
        return DB::transaction(function () use ($business, $location, $attributes) {
            $locked = $this->lockBusiness($business);

            $target = $this->locationRepository->findByUidForBusiness($locked, (string) $location->uid);

            if ($target === null) {
                throw new WorkspaceBusinessNotFoundException((int) $locked->id);
            }

            $this->locationRepository->updateDetails($target, $attributes);

            return $target->refresh();
        });
    }

    /**
     * Read-time capacity for presentation (contract §7.5.1). Pure read.
     */
    public function capacityFor(Business $business): LocationSlotCapacityDecision
    {
        return $this->entitlements->decideLocationSlotCapacity($business);
    }

    /**
     * Every location of the Business, active and archived, oldest first.
     */
    public function locationsFor(Business $business): Collection
    {
        return $this->locationRepository->allForBusiness($business);
    }

    public function findLocation(Business $business, string $uid): ?BusinessLocation
    {
        return $this->locationRepository->findByUidForBusiness($business, $uid);
    }

    /**
     * Contract §7.5.3 — complimentary grandfathered excess is consumed as
     * the Business falls back toward its normal entitlement, so it never
     * becomes a transferable free slot. Paid allocations are untouched.
     *
     * Called only after an archive has already reduced the active count.
     */
    private function consumeGrandfatheredExcess(Business $business): void
    {
        $grandfathered = (int) $business->grandfathered_location_slots;

        if ($grandfathered === 0) {
            return;
        }

        $decision = $this->entitlements->decideLocationSlotCapacity($business);
        $normalCapacity = $decision->includedSlots + $decision->additionalSlotsAllocated;
        $activeCount = $decision->activeLocationCount;

        // How much complimentary allowance the remaining active locations
        // still genuinely need. Anything above that is consumed for good.
        $stillNeeded = max(0, $activeCount - $normalCapacity);

        if ($stillNeeded >= $grandfathered) {
            return;
        }

        $business->forceFill(['grandfathered_location_slots' => $stillNeeded])->save();
    }

    private function lockBusiness(Business $business): Business
    {
        $locked = $this->businessRepository->findForUpdate((int) $business->id);

        if ($locked === null) {
            throw new WorkspaceBusinessNotFoundException((int) $business->id);
        }

        return $locked;
    }
}
