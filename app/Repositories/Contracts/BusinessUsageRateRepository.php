<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageRate;

interface BusinessUsageRateRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessUsageRate;

    public function findByMeterAndVersion(string $meterKey, int $version): ?BusinessUsageRate;

    public function latestVersionForFeature(string $featureKey): int;

    public function create(array $attributes): BusinessUsageRate;
}
