<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;

/**
 * Plain data access for business_locations. Capacity and lifecycle RULES
 * live in App\Library\Business\BusinessLocationManager (the one canonical
 * create/reactivate boundary, contract §7.3b) and EntitlementManager; the
 * methods that can raise a Business's ACTIVE location count —
 * upsertPrimary(), createForBusiness() and reactivate() — have no production
 * caller outside that manager, which is asserted by the source-boundary
 * inventory test (T-LOC-9).
 */
interface BusinessLocationRepository extends BaseRepository
{
    public function findPrimary(Business $business): ?BusinessLocation;

    public function upsertPrimary(Business $business, array $attributes): BusinessLocation;

    public function setPrimary(BusinessLocation $location): BusinessLocation;

    /** Slice 1A — every location of the Business, primary first, then active, then archived. */
    public function forBusiness(Business $business): Collection;

    public function findForBusinessByUid(Business $business, string $uid): ?BusinessLocation;

    public function findForUpdate(int $id): ?BusinessLocation;

    /**
     * Active locations counted with a locking read, so the answer is the
     * latest committed state even inside a longer outer transaction.
     */
    public function countActiveForUpdate(int $businessId): int;

    /** @return array{active: int, archived: int} */
    public function countByLifecycle(int $businessId): array;

    /** Creates an ACTIVE, non-primary location. Capacity is the caller's job. */
    public function createForBusiness(Business $business, array $attributes): BusinessLocation;

    /** Edits details only: business, primary flag and lifecycle are never touched. */
    public function updateDetails(BusinessLocation $location, array $attributes): BusinessLocation;

    public function archive(BusinessLocation $location): BusinessLocation;

    public function reactivate(BusinessLocation $location): BusinessLocation;
}
