<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessFundingAttemptTransition;
use App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository;

class EloquentBusinessFundingAttemptTransitionRepository extends EloquentBaseRepository implements BusinessFundingAttemptTransitionRepository
{
    public function __construct(BusinessFundingAttemptTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): BusinessFundingAttemptTransition
    {
        /** @var BusinessFundingAttemptTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }
}
