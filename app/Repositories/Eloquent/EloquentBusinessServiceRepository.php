<?php

namespace App\Repositories\Eloquent;

use App\Enums\Business\BusinessServiceStatus;
use App\Models\Business;
use App\Models\BusinessService;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EloquentBusinessServiceRepository extends EloquentBaseRepository implements BusinessServiceRepository
{
    public function __construct(BusinessService $service)
    {
        parent::__construct($service);
    }

    public function activeForBusiness(Business $business): Collection
    {
        return $business->services()
            ->where('status', BusinessServiceStatus::Active->value)
            ->orderBy('sort_order')
            ->get();
    }

    public function findOwned(Business $business, int $serviceId): ?BusinessService
    {
        return $business->services()->where('id', $serviceId)->first();
    }

    /**
     * Create/update/inactivate business services in a single pass.
     *
     * Omitted existing services become inactive, not deleted. Existing slugs
     * are preserved even when the name changes. Exactly one active service
     * ends up primary: the submitted primary if valid and active, otherwise
     * the first active service by sort order.
     */
    public function syncForBusiness(Business $business, array $services): Collection
    {
        return DB::transaction(function () use ($business, $services) {
            $existing = $business->services()->get()->keyBy('id');
            $keptIds = [];
            $requestedPrimaryId = null;

            foreach ($services as $item) {
                $id = $item['id'] ?? null;
                $service = null;

                if ($id !== null) {
                    $service = $existing->get((int) $id);

                    if (! $service) {
                        throw new InvalidArgumentException("Service [{$id}] does not belong to this business.");
                    }
                }

                $attributes = array_filter(
                    Arr::only($item, ['name', 'description', 'starting_price', 'currency_code', 'status', 'sort_order']),
                    fn ($value) => $value !== null
                );

                if ($service) {
                    $service->fill($attributes);
                    $service->save();
                } else {
                    $service = $business->services()->create(array_merge($attributes, [
                        'slug' => $this->generateUniqueSlug($business, $item['name']),
                        'status' => $attributes['status'] ?? BusinessServiceStatus::Active->value,
                    ]));
                }

                $keptIds[] = $service->id;

                if ($requestedPrimaryId === null && ! empty($item['is_primary'])) {
                    $requestedPrimaryId = $service->id;
                }
            }

            $business->services()
                ->whereNotIn('id', $keptIds)
                ->update(['status' => BusinessServiceStatus::Inactive->value]);

            $this->resolvePrimary($business, $keptIds, $requestedPrimaryId);

            return $business->services()->orderBy('sort_order')->get();
        });
    }

    public function setPrimary(BusinessService $service): BusinessService
    {
        if ($service->status !== BusinessServiceStatus::Active) {
            throw new InvalidArgumentException('Only an active service may be set as primary.');
        }

        return DB::transaction(function () use ($service) {
            $this->query()
                ->where('business_id', $service->business_id)
                ->where('id', '!=', $service->id)
                ->update(['is_primary' => false]);

            $service->is_primary = true;
            $service->save();

            return $service;
        });
    }

    private function resolvePrimary(Business $business, array $keptIds, ?int $requestedPrimaryId): void
    {
        $primaryId = null;

        if ($requestedPrimaryId !== null) {
            $requested = $business->services()
                ->whereIn('id', $keptIds)
                ->where('status', BusinessServiceStatus::Active->value)
                ->where('id', $requestedPrimaryId)
                ->first();

            $primaryId = $requested?->id;
        }

        if ($primaryId === null) {
            $fallback = $business->services()
                ->whereIn('id', $keptIds)
                ->where('status', BusinessServiceStatus::Active->value)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            $primaryId = $fallback?->id;
        }

        $business->services()->update(['is_primary' => false]);

        if ($primaryId !== null) {
            $business->services()->where('id', $primaryId)->update(['is_primary' => true]);
        }
    }

    private function generateUniqueSlug(Business $business, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while ($business->services()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
