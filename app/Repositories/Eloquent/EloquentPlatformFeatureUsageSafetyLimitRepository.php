<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformFeatureUsageSafetyLimit;
use App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository;

class EloquentPlatformFeatureUsageSafetyLimitRepository extends EloquentBaseRepository implements PlatformFeatureUsageSafetyLimitRepository
{
    public function __construct(PlatformFeatureUsageSafetyLimit $limit)
    {
        parent::__construct($limit);
    }

    public function findByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit
    {
        return $this->query()->where('feature_key', $featureKey)->first();
    }

    public function findForUpdateByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit
    {
        return $this->query()->where('feature_key', $featureKey)->lockForUpdate()->first();
    }

    public function create(array $attributes): PlatformFeatureUsageSafetyLimit
    {
        /** @var PlatformFeatureUsageSafetyLimit $limit */
        $limit = $this->make($attributes);
        $limit->save();

        return $limit;
    }

    public function update(PlatformFeatureUsageSafetyLimit $limit, array $attributes): PlatformFeatureUsageSafetyLimit
    {
        $limit->fill($attributes);
        $limit->save();

        return $limit;
    }
}
