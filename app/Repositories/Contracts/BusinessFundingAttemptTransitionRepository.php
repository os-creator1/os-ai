<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessFundingAttemptTransition;

interface BusinessFundingAttemptTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessFundingAttemptTransition;
}
