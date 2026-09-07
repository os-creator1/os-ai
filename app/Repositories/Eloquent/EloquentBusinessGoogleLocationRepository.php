<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use App\Repositories\Contracts\BusinessGoogleLocationRepository;
use Illuminate\Support\Collection;

class EloquentBusinessGoogleLocationRepository implements BusinessGoogleLocationRepository
{
    public function allForBusiness(Business $business): Collection
    {
        return BusinessGoogleLocation::query()
            ->where('business_id', $business->id)
            ->with('businessLocation')
            ->orderBy('id')
            ->get();
    }

    /**
     * business_google_locations.business_location_id is UNIQUE, so keying
     * by it is lossless — no binding can be dropped by the re-key.
     */
    public function allForBusinessKeyedByLocationId(Business $business): Collection
    {
        return $this->allForBusiness($business)
            ->keyBy(fn (BusinessGoogleLocation $binding) => (int) $binding->business_location_id);
    }

    /**
     * Contract §15.3 — resolved THROUGH the Business, never by uid alone.
     */
    public function findByUidForBusiness(Business $business, string $uid): ?BusinessGoogleLocation
    {
        return BusinessGoogleLocation::query()
            ->where('business_id', $business->id)
            ->where('uid', $uid)
            ->with('businessLocation')
            ->first();
    }
}
