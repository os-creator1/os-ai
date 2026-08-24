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

    public function findByMeterAndVersion(string $meterKey, int $version): ?BusinessUsageRate
    {
        return $this->query()->where('meter_key', $meterKey)->where('version', $version)->first();
    }

    public function latestVersionForMeter(string $meterKey): int
    {
        return (int) ($this->query()->where('meter_key', $meterKey)->max('version') ?? 0);
    }

    public function create(array $attributes): BusinessUsageRate
    {
        /** @var BusinessUsageRate $rate */
        $rate = $this->make($attributes);
        $rate->save();

        return $rate;
    }
}
