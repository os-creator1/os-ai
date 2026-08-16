<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageWallet;
use App\Repositories\Contracts\BusinessUsageWalletRepository;

class EloquentBusinessUsageWalletRepository extends EloquentBaseRepository implements BusinessUsageWalletRepository
{
    public function __construct(BusinessUsageWallet $wallet)
    {
        parent::__construct($wallet);
    }

    public function findByBusinessId(int $businessId): ?BusinessUsageWallet
    {
        return $this->query()->where('business_id', $businessId)->first();
    }

    public function findForUpdateByBusinessId(int $businessId): ?BusinessUsageWallet
    {
        return $this->query()->where('business_id', $businessId)->lockForUpdate()->first();
    }

    public function create(array $attributes): BusinessUsageWallet
    {
        /** @var BusinessUsageWallet $wallet */
        $wallet = $this->make($attributes);
        $wallet->save();

        return $wallet;
    }

    public function update(BusinessUsageWallet $wallet, array $attributes): BusinessUsageWallet
    {
        $wallet->fill($attributes);
        $wallet->save();

        return $wallet;
    }
}
