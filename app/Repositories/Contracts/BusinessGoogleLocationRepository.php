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
 *
 * MULTI-LOCATION CORRECTION — there is deliberately NO singular
 * findForBusiness(Business) here any more. The schema allows one binding
 * per BusinessLocation and therefore MANY bindings per Business
 * (business_google_locations.business_location_id is UNIQUE;
 * business_id is only indexed), so a singular accessor had no unambiguous
 * meaning: it silently returned the lowest-id row and made every other
 * binding invisible on the overview, in settings and in the comparison.
 * It was removed rather than deprecated so the ambiguity cannot come back.
 */
interface BusinessGoogleLocationRepository
{
    /**
     * Every binding owned by this Business, oldest first.
     *
     * @return Collection<int, BusinessGoogleLocation>
     */
    public function allForBusiness(Business $business): Collection;

    /**
     * The same set keyed by the LOCAL business_locations.id it binds, so a
     * caller can decide per platform location whether it is already bound
     * without an N+1 lookup (contract §25.4).
     *
     * @return Collection<int, BusinessGoogleLocation>
     */
    public function allForBusinessKeyedByLocationId(Business $business): Collection;

    /**
     * Contract §15.3 — resolved THROUGH the Business, never by uid alone.
     */
    public function findByUidForBusiness(Business $business, string $uid): ?BusinessGoogleLocation;
}
