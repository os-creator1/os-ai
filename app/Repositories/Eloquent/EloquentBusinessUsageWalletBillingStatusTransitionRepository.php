<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageWalletBillingStatusTransition;
use App\Repositories\Contracts\BusinessUsageWalletBillingStatusTransitionRepository;
use Illuminate\Support\Collection;

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

    public function recentForBusiness(int $businessId, int $limit = 20): Collection
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
