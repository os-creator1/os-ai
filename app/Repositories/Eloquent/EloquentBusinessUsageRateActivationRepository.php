<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageRateActivation;
use App\Repositories\Contracts\BusinessUsageRateActivationRepository;

class EloquentBusinessUsageRateActivationRepository extends EloquentBaseRepository implements BusinessUsageRateActivationRepository
{
    public function __construct(BusinessUsageRateActivation $activation)
    {
        parent::__construct($activation);
    }

    public function create(array $attributes): BusinessUsageRateActivation
    {
        /** @var BusinessUsageRateActivation $activation */
        $activation = $this->make($attributes);
        $activation->save();

        return $activation;
    }
}
