<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanFeature;
use Illuminate\Support\Collection;

/**
 * Plain data-access contract — packaging only (RFC-004 §10.2). Never
 * consults PlatformFeatureRegistry::isAvailable(); that is a separate,
 * code-backed concern checked strictly before this table is even read
 * (§11, §14).
 */
interface WorkspacePlanFeatureRepository extends BaseRepository
{
    /**
     * @return Collection<int, string> feature_key values the tier includes.
     */
    public function featureKeysForCatalog(WorkspacePlanCatalog $catalog): Collection;

    public function includesFeature(WorkspacePlanCatalog $catalog, string $featureKey): bool;

    /**
     * $attributes['feature_key'] must be a valid PlatformFeature::cases()
     * value — validated here at the application layer, since no plain
     * foreign key can express "must be a valid enum case" (RFC-004 §10.2).
     */
    public function create(array $attributes): WorkspacePlanFeature;
}
