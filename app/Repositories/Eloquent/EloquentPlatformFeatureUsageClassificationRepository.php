<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformFeatureUsageClassification;
use App\Repositories\Contracts\PlatformFeatureUsageClassificationRepository;

class EloquentPlatformFeatureUsageClassificationRepository extends EloquentBaseRepository implements PlatformFeatureUsageClassificationRepository
{
    public function __construct(PlatformFeatureUsageClassification $classification)
    {
        parent::__construct($classification);
    }

    public function findByFeatureKey(string $featureKey): ?PlatformFeatureUsageClassification
    {
        return $this->query()->where('feature_key', $featureKey)->first();
    }

    public function findForUpdateByFeatureKey(string $featureKey): ?PlatformFeatureUsageClassification
    {
        return $this->query()->where('feature_key', $featureKey)->lockForUpdate()->first();
    }

    public function create(array $attributes): PlatformFeatureUsageClassification
    {
        /** @var PlatformFeatureUsageClassification $classification */
        $classification = $this->make($attributes);
        $classification->save();

        return $classification;
    }

    public function update(PlatformFeatureUsageClassification $classification, array $attributes): PlatformFeatureUsageClassification
    {
        $classification->fill($attributes);
        $classification->save();

        return $classification;
    }
}
