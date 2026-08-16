<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageRateActivation;

interface BusinessUsageRateActivationRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageRateActivation;
}
