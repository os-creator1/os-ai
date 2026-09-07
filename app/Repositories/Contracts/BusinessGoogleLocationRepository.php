<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use Illuminate\Support\Collection;

/**
 * GBP Slice A contract §19.3 — the READ seam for location bindings.
 *
 * Every finder takes a Business; a binding uid is only ever resolved
 * THROUGH an already-resolved Business (§15.3), so a foreign uid 404s
 * exactly like an unknown one.
 */
interface BusinessGoogleLocationRepository
{
    public function findForBusiness(Business $business): ?BusinessGoogleLocation;

    /**
     * @return Collection<int, BusinessGoogleLocation>
     */
    public function allForBusiness(Business $business): Collection;

    public function findByUidForBusiness(Business $business, string $uid): ?BusinessGoogleLocation;
}
