<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageAddonPurchase;

interface BusinessUsageAddonPurchaseRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessUsageAddonPurchase;

    public function findByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase;

    public function create(array $attributes): BusinessUsageAddonPurchase;

    public function update(BusinessUsageAddonPurchase $purchase, array $attributes): BusinessUsageAddonPurchase;
}
