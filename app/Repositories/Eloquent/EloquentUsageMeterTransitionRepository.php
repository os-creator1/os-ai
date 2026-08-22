<?php

namespace App\Repositories\Eloquent;

use App\Models\UsageMeterTransition;
use App\Repositories\Contracts\UsageMeterTransitionRepository;

class EloquentUsageMeterTransitionRepository extends EloquentBaseRepository implements UsageMeterTransitionRepository
{
    public function __construct(UsageMeterTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): UsageMeterTransition
    {
        /** @var UsageMeterTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }
}
