<?php

namespace App\Repositories\Eloquent;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

    /**
     * Slice 1A — create one ACTIVE location. Primary status is never
     * inferred here; BusinessLocationManager decides it explicitly.
     */
    public function createActive(Business $business, array $attributes): BusinessLocation
    {
        $attributes = Arr::except($attributes, ['business_id', 'is_primary', 'lifecycle_state', 'archived_at']);

        $location = $business->locations()->create($attributes);

        $location->forceFill([
            'lifecycle_state' => BusinessLocationLifecycleState::Active,
            'archived_at' => null,
        ])->save();

        return $location->refresh();
    }

    /**
     * Active AND archived, oldest first. Archived rows are never hidden.
     */
    public function allForBusiness(Business $business): Collection
    {
        return $business->locations()->orderBy('id')->get();
    }

    public function findByUidForBusiness(Business $business, string $uid): ?BusinessLocation
    {
        return $business->locations()->where('uid', $uid)->first();
    }

    /**
     * Slice 1A — edit details only. lifecycle_state, is_primary and
     * business_id are stripped, so an edit can never change capacity
     * consumption or move a location between Businesses.
     */
    public function updateDetails(BusinessLocation $location, array $attributes): BusinessLocation
    {
        $location->fill(Arr::except($attributes, ['business_id', 'is_primary', 'lifecycle_state', 'archived_at']));
        $location->save();

        return $location->refresh();
    }

    /**
     * The capacity COUNT (contract §7.3) — active rows only.
     */
    public function countActiveForBusiness(Business $business): int
    {
        return (int) $business->locations()
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->count();
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
