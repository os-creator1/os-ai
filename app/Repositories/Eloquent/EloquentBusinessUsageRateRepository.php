<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageRate;
use App\Repositories\Contracts\BusinessUsageRateRepository;

class EloquentBusinessUsageRateRepository extends EloquentBaseRepository implements BusinessUsageRateRepository
{
    public function __construct(BusinessUsageRate $rate)
    {
        parent::__construct($rate);
    }

    public function findById(int $id): ?BusinessUsageRate
    {
        return $this->query()->find($id);
    }

    public function findByFeatureAndVersion(string $featureKey, int $version): ?BusinessUsageRate
    {
        return $this->query()->where('feature_key', $featureKey)->where('version', $version)->first();
    }

    public function latestVersionForFeature(string $featureKey): int
    {
        return (int) ($this->query()->where('feature_key', $featureKey)->max('version') ?? 0);
    }

    public function create(array $attributes): BusinessUsageRate
    {
        /** @var BusinessUsageRate $rate */
        $rate = $this->make($attributes);
        $rate->save();

        return $rate;
    }
}
