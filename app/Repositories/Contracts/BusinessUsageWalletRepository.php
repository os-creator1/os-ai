<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageWallet;

/**
 * Plain data-access contract — no accounting policy or authority logic.
 * Every invariant (balance non-negativity, currency resolution, period
 * rollover) is UsageWalletManager's exclusive responsibility.
 */
interface BusinessUsageWalletRepository extends BaseRepository
{
    public function findByBusinessId(int $businessId): ?BusinessUsageWallet;

    /**
     * Row-locking variant of findByBusinessId(), for callers that must
     * hold the wallet row lock for the duration of a transaction
     * (RFC-005 §13's reserve/commit/release algorithms).
     */
    public function findForUpdateByBusinessId(int $businessId): ?BusinessUsageWallet;

    public function create(array $attributes): BusinessUsageWallet;

    public function update(BusinessUsageWallet $wallet, array $attributes): BusinessUsageWallet;
}
