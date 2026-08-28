<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageAddonPurchase;

interface BusinessUsageAddonPurchaseRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessUsageAddonPurchase;

    public function findByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase;

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §2.2 —
     * the row-locked counterpart of findByFundingAttemptId(), used by
     * completeAddonPurchaseUnderLock() to serialize the purchase-level
     * completion mutation across every concurrent or replayed caller.
     */
    public function findForUpdateByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase;

    public function create(array $attributes): BusinessUsageAddonPurchase;

    public function update(BusinessUsageAddonPurchase $purchase, array $attributes): BusinessUsageAddonPurchase;
}
