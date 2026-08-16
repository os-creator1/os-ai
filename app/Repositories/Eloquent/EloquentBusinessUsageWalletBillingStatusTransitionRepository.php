<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageWalletBillingStatusTransition;
use App\Repositories\Contracts\BusinessUsageWalletBillingStatusTransitionRepository;

class EloquentBusinessUsageWalletBillingStatusTransitionRepository extends EloquentBaseRepository implements BusinessUsageWalletBillingStatusTransitionRepository
{
    public function __construct(BusinessUsageWalletBillingStatusTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): BusinessUsageWalletBillingStatusTransition
    {
        /** @var BusinessUsageWalletBillingStatusTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }
}
