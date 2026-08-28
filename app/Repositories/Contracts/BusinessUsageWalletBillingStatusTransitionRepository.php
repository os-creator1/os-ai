<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageWalletBillingStatusTransition;
use Illuminate\Support\Collection;

interface BusinessUsageWalletBillingStatusTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageWalletBillingStatusTransition;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.4 — the admin
     * dashboard's own bounded billing-status history read.
     */
    public function recentForBusiness(int $businessId, int $limit = 20): Collection;
}
