<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageAddonPurchase;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;

class EloquentBusinessUsageAddonPurchaseRepository extends EloquentBaseRepository implements BusinessUsageAddonPurchaseRepository
{
    public function __construct(BusinessUsageAddonPurchase $purchase)
    {
        parent::__construct($purchase);
    }

    public function findById(int $id): ?BusinessUsageAddonPurchase
    {
        return $this->query()->find($id);
    }

    public function findByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase
    {
        return $this->query()->where('funding_attempt_id', $fundingAttemptId)->first();
    }

    public function findForUpdateByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase
    {
        return $this->query()->where('funding_attempt_id', $fundingAttemptId)->lockForUpdate()->first();
    }

    public function create(array $attributes): BusinessUsageAddonPurchase
    {
        /** @var BusinessUsageAddonPurchase $purchase */
        $purchase = $this->make($attributes);
        $purchase->save();

        return $purchase;
    }

    public function update(BusinessUsageAddonPurchase $purchase, array $attributes): BusinessUsageAddonPurchase
    {
        $purchase->fill($attributes);
        $purchase->save();

        return $purchase;
    }
}
