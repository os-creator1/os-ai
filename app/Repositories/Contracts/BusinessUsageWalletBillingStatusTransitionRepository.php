<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageWalletBillingStatusTransition;

interface BusinessUsageWalletBillingStatusTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageWalletBillingStatusTransition;
}
