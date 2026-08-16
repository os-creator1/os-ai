<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformFeatureUsageClassificationTransition;
use App\Repositories\Contracts\PlatformFeatureUsageClassificationTransitionRepository;

class EloquentPlatformFeatureUsageClassificationTransitionRepository extends EloquentBaseRepository implements PlatformFeatureUsageClassificationTransitionRepository
{
    public function __construct(PlatformFeatureUsageClassificationTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): PlatformFeatureUsageClassificationTransition
    {
        /** @var PlatformFeatureUsageClassificationTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }
}
