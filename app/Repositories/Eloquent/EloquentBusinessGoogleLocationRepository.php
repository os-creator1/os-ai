<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use App\Repositories\Contracts\BusinessGoogleLocationRepository;
use Illuminate\Support\Collection;

class EloquentBusinessGoogleLocationRepository implements BusinessGoogleLocationRepository
{
    public function findForBusiness(Business $business): ?BusinessGoogleLocation
    {
        return BusinessGoogleLocation::query()
            ->where('business_id', $business->id)
            ->with('businessLocation')
            ->orderBy('id')
            ->first();
    }

    public function allForBusiness(Business $business): Collection
    {
        return BusinessGoogleLocation::query()
            ->where('business_id', $business->id)
            ->with('businessLocation')
            ->orderBy('id')
            ->get();
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
