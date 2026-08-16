<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageLimitTransition;
use App\Repositories\Contracts\BusinessUsageLimitTransitionRepository;

class EloquentBusinessUsageLimitTransitionRepository extends EloquentBaseRepository implements BusinessUsageLimitTransitionRepository
{
    public function __construct(BusinessUsageLimitTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): BusinessUsageLimitTransition
    {
        /** @var BusinessUsageLimitTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }
}
