<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EloquentBusinessLocationRepository extends EloquentBaseRepository implements BusinessLocationRepository
{
    public function __construct(BusinessLocation $location)
    {
        parent::__construct($location);
    }

    public function findPrimary(Business $business): ?BusinessLocation
    {
        return $business->locations()->where('is_primary', true)->first();
    }

    public function upsertPrimary(Business $business, array $attributes): BusinessLocation
    {
        return DB::transaction(function () use ($business, $attributes) {
            $attributes = Arr::except($attributes, ['business_id', 'is_primary']);

            $location = $business->locations()->where('is_primary', true)->first();

            if ($location) {
                $location->fill($attributes);
                $location->save();
            } else {
                $location = $business->locations()->create($attributes);
                $location->is_primary = true;
                $location->save();
            }

            $business->locations()
                ->where('id', '!=', $location->id)
                ->update(['is_primary' => false]);

            return $location->refresh();
        });
    }

    public function setPrimary(BusinessLocation $location): BusinessLocation
    {
        return DB::transaction(function () use ($location) {
            $this->query()
                ->where('business_id', $location->business_id)
                ->where('id', '!=', $location->id)
                ->update(['is_primary' => false]);

            $location->is_primary = true;
            $location->save();

            return $location;
        });
    }
}
