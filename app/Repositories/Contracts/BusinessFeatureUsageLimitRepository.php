<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessFeatureUsageLimit;
use Illuminate\Support\Collection;

/**
 * Plain data-access contract — no accounting policy or authority logic.
 * Every invariant (safety-limit bounding, PlatformFeatureRegistry
 * validation) is UsageWalletManager's exclusive responsibility.
 */
interface BusinessFeatureUsageLimitRepository extends BaseRepository
{
    public function findByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureUsageLimit;

    public function findForUpdateByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureUsageLimit;

    /**
     * @return Collection<int, BusinessFeatureUsageLimit>
     */
    public function forBusiness(int $businessId): Collection;

    public function create(array $attributes): BusinessFeatureUsageLimit;

    public function update(BusinessFeatureUsageLimit $limit, array $attributes): BusinessFeatureUsageLimit;

    public function delete(BusinessFeatureUsageLimit $limit): void;
}
