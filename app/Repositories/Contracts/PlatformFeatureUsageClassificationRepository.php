<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformFeatureUsageClassification;

interface PlatformFeatureUsageClassificationRepository extends BaseRepository
{
    public function findByFeatureKey(string $featureKey): ?PlatformFeatureUsageClassification;

    /**
     * Row-locking variant, the canonical per-feature serialization point
     * for setActiveRate()/activateMetering() (RFC-005 §11).
     */
    public function findForUpdateByFeatureKey(string $featureKey): ?PlatformFeatureUsageClassification;

    public function create(array $attributes): PlatformFeatureUsageClassification;

    public function update(PlatformFeatureUsageClassification $classification, array $attributes): PlatformFeatureUsageClassification;
}
