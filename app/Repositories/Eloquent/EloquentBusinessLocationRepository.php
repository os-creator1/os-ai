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
    /**
     * Columns a details edit or a create may never set: ownership, the
     * primary flag and lifecycle move only through their own methods.
     */
    private const PROTECTED_ATTRIBUTES = ['business_id', 'is_primary', 'lifecycle_state', 'archived_at', 'uid'];

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

    public function forBusiness(Business $business): Collection
    {
        return $business->locations()
            ->orderByDesc('is_primary')
            ->orderByRaw('CASE WHEN lifecycle_state = ? THEN 0 ELSE 1 END', [BusinessLocationLifecycleState::Active->value])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function findForBusinessByUid(Business $business, string $uid): ?BusinessLocation
    {
        return $business->locations()->where('uid', $uid)->first();
    }

    public function findForUpdate(int $id): ?BusinessLocation
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function countActiveForUpdate(int $businessId): int
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->lockForUpdate()
            ->count();
    }

    public function countByLifecycle(int $businessId): array
    {
        $counts = $this->query()
            ->where('business_id', $businessId)
            ->groupBy('lifecycle_state')
            ->pluck(DB::raw('COUNT(*) as aggregate'), 'lifecycle_state');

        return [
            'active' => (int) ($counts[BusinessLocationLifecycleState::Active->value] ?? 0),
            'archived' => (int) ($counts[BusinessLocationLifecycleState::Archived->value] ?? 0),
        ];
    }

    public function createForBusiness(Business $business, array $attributes): BusinessLocation
    {
        /** @var BusinessLocation $location */
        $location = $business->locations()->make(Arr::except($attributes, self::PROTECTED_ATTRIBUTES));
        $location->is_primary = false;
        $location->lifecycle_state = BusinessLocationLifecycleState::Active;
        $location->archived_at = null;
        $location->save();

        return $location->refresh();
    }

    public function updateDetails(BusinessLocation $location, array $attributes): BusinessLocation
    {
        // A cleared name keeps the existing one: the column is NOT NULL.
        if (array_key_exists('name', $attributes) && ($attributes['name'] === null || trim((string) $attributes['name']) === '')) {
            unset($attributes['name']);
        }

        $location->fill(Arr::except($attributes, self::PROTECTED_ATTRIBUTES));
        $location->save();

        return $location->refresh();
    }

    public function archive(BusinessLocation $location): BusinessLocation
    {
        $location->lifecycle_state = BusinessLocationLifecycleState::Archived;
        $location->archived_at = now();
        $location->is_primary = false;
        $location->save();

        return $location->refresh();
    }

    public function reactivate(BusinessLocation $location): BusinessLocation
    {
        $location->lifecycle_state = BusinessLocationLifecycleState::Active;
        $location->archived_at = null;
        $location->save();

        return $location->refresh();
    }
}
