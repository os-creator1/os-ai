<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageAddonPurchaseTransition;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository;
use Illuminate\Support\Collection;

class EloquentBusinessUsageAddonPurchaseTransitionRepository extends EloquentBaseRepository implements BusinessUsageAddonPurchaseTransitionRepository
{
    public function __construct(BusinessUsageAddonPurchaseTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): BusinessUsageAddonPurchaseTransition
    {
        /** @var BusinessUsageAddonPurchaseTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }

    public function forPurchase(int $purchaseId): Collection
    {
        return $this->query()
            ->where('purchase_id', $purchaseId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
