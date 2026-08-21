<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageAddonPurchaseTransition;
use Illuminate\Support\Collection;

/**
 * Append-only (M4 contract §20) — deliberately no update() method.
 */
interface BusinessUsageAddonPurchaseTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageAddonPurchaseTransition;

    /**
     * @return Collection<int, BusinessUsageAddonPurchaseTransition>
     */
    public function forPurchase(int $purchaseId): Collection;
}
