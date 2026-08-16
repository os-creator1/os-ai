<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageLimitTransition;

interface BusinessUsageLimitTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageLimitTransition;
}
