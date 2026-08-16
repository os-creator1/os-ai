<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessFeatureUsageLimit;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use Illuminate\Support\Collection;

class EloquentBusinessFeatureUsageLimitRepository extends EloquentBaseRepository implements BusinessFeatureUsageLimitRepository
{
    public function __construct(BusinessFeatureUsageLimit $limit)
    {
        parent::__construct($limit);
    }

    public function findByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureUsageLimit
    {
        return $this->query()->where('business_id', $businessId)->where('feature_key', $featureKey)->first();
    }

    public function findForUpdateByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureUsageLimit
    {
        return $this->query()->where('business_id', $businessId)->where('feature_key', $featureKey)->lockForUpdate()->first();
    }

    public function forBusiness(int $businessId): Collection
    {
        return $this->query()->where('business_id', $businessId)->orderBy('feature_key')->get();
    }

    public function create(array $attributes): BusinessFeatureUsageLimit
    {
        /** @var BusinessFeatureUsageLimit $limit */
        $limit = $this->make($attributes);
        $limit->save();

        return $limit;
    }

    public function update(BusinessFeatureUsageLimit $limit, array $attributes): BusinessFeatureUsageLimit
    {
        $limit->fill($attributes);
        $limit->save();

        return $limit;
    }

    public function delete(BusinessFeatureUsageLimit $limit): void
    {
        $limit->delete();
    }
}
