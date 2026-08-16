<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformFeatureUsageSafetyLimit;

interface PlatformFeatureUsageSafetyLimitRepository extends BaseRepository
{
    public function findByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit;

    public function findForUpdateByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit;

    public function create(array $attributes): PlatformFeatureUsageSafetyLimit;

    public function update(PlatformFeatureUsageSafetyLimit $limit, array $attributes): PlatformFeatureUsageSafetyLimit;
}
