<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspacePlanCatalogPricingChange;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-004 Amendment 2 §8) — deliberately no update() method,
 * mirroring WorkspaceEntitlementTransitionRepository's exact precedent.
 */
interface WorkspacePlanCatalogPricingChangeRepository extends BaseRepository
{
    public function create(array $attributes): WorkspacePlanCatalogPricingChange;

    /**
     * @return Collection<int, WorkspacePlanCatalogPricingChange>
     */
    public function forCatalog(int $catalogId): Collection;
}
