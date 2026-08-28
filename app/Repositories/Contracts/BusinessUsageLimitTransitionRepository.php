<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageLimitTransition;
use Illuminate\Support\Collection;

interface BusinessUsageLimitTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageLimitTransition;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.4 — the admin
     * dashboard's own bounded per-Business spend-cap/feature-limit
     * history read.
     */
    public function recentForBusiness(int $businessId, int $limit = 20): Collection;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.4 — the platform
     * safety-limits admin screen's own bounded, platform-scoped-only
     * history read (business_id IS NULL, limit_type =
     * platform_safety_limit).
     */
    public function recentPlatformSafetyLimitHistory(int $limit = 50): Collection;
}
