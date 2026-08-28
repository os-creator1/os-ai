<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformFeatureUsageSafetyLimit;
use Illuminate\Support\Collection;

interface PlatformFeatureUsageSafetyLimitRepository extends BaseRepository
{
    public function findByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit;

    public function findForUpdateByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit;

    public function create(array $attributes): PlatformFeatureUsageSafetyLimit;

    public function update(PlatformFeatureUsageSafetyLimit $limit, array $attributes): PlatformFeatureUsageSafetyLimit;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.4 — the platform-
     * wide safety-limits admin screen's own list read. Not bounded by a
     * $limit: this table holds at most one row per platform feature key,
     * a genuinely small, operator-controlled set.
     */
    public function all(): Collection;
}
