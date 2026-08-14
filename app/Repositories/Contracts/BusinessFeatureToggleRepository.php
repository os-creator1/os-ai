<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessFeatureToggle;

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
     * $attributes['feature_key'] must be a valid PlatformFeature::cases()
     * value — validated here at the application layer (RFC-004 §10.6).
     */
    public function create(array $attributes): BusinessFeatureToggle;

    public function delete(BusinessFeatureToggle $toggle): void;
}
