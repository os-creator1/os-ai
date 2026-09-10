<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessFeatureToggle;
use Illuminate\Support\Collection;

/**
 * Plain data-access contract. A row's mere existence is "disabled here"
 * (RFC-004 §10.6) — re-enabling deletes the row. Whether a toggle may be
 * created at all for a given feature (the Workspace must already be
 * effectively entitled to it) is exclusively EntitlementManager's
 * responsibility (M2, §16) — this repository never makes that decision.
 */
interface BusinessFeatureToggleRepository extends BaseRepository
{
    public function findByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureToggle;

    /**
     * Every toggle this Business holds, in one read.
     *
     * Additive, for Slice 2A's menu snapshot (§6.3/§6.5), for the same reason
     * as WorkspaceEntitlementOverrideRepository::allForWorkspace(): the menu's
     * query cost must not grow with the number of entries it checks. A toggle
     * is at most one row per PlatformFeature, so the result is bounded by the
     * enum rather than by tenant data.
     *
     * Data access only — a row's mere existence still means "disabled here",
     * and interpreting that stays EntitlementManager's job.
     *
     * @return \Illuminate\Support\Collection<string, BusinessFeatureToggle> keyed by feature_key
     */
    public function allForBusiness(int $businessId): Collection;

    /**
     * $attributes['feature_key'] must be a valid PlatformFeature::cases()
     * value — validated here at the application layer (RFC-004 §10.6).
     */
    public function create(array $attributes): BusinessFeatureToggle;

    public function delete(BusinessFeatureToggle $toggle): void;
}
