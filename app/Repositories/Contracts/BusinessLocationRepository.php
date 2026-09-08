<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;

/**
 * Customer Experience Slice 1A extends this seam from "the primary
 * location" to "the Business's physical locations", because a Business may
 * legitimately hold several branches or service areas.
 *
 * EVERY capacity-increasing write (create and reactivate) goes through
 * App\Library\Business\BusinessLocationManager, never directly through
 * this repository from a controller (contract §7.3b). The repository stays
 * a dumb persistence seam and performs no capacity check of its own.
 */
interface BusinessLocationRepository extends BaseRepository
{
    public function findPrimary(Business $business): ?BusinessLocation;

    public function upsertPrimary(Business $business, array $attributes): BusinessLocation;

    public function setPrimary(BusinessLocation $location): BusinessLocation;

    /**
     * Slice 1A — create one ACTIVE location. Never sets primary status;
     * the caller decides that explicitly.
     */
    public function createActive(Business $business, array $attributes): BusinessLocation;

    /**
     * Slice 1A — every location of this Business, active and archived,
     * oldest first. Archived rows are never hidden (§7.3a rule 8).
     *
     * @return Collection<int, BusinessLocation>
     */
    public function allForBusiness(Business $business): Collection;

    /**
     * Slice 1A — resolved THROUGH the Business, so a foreign uid is
     * indistinguishable from an unknown one.
     */
    public function findByUidForBusiness(Business $business, string $uid): ?BusinessLocation;

    /**
     * Slice 1A — the COUNT that capacity is evaluated against. Active rows
     * only; archived rows consume nothing.
     */
    public function countActiveForBusiness(Business $business): int;

    /**
     * Slice 1A — edit an existing location's details. Never touches
     * lifecycle_state, is_primary or business_id: those move only through
     * the canonical boundary's archive/reactivate/primary paths.
     */
    public function updateDetails(BusinessLocation $location, array $attributes): BusinessLocation;
}
