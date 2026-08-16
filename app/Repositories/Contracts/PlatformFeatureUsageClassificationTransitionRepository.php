<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformFeatureUsageClassificationTransition;

interface PlatformFeatureUsageClassificationTransitionRepository extends BaseRepository
{
    public function create(array $attributes): PlatformFeatureUsageClassificationTransition;
}
