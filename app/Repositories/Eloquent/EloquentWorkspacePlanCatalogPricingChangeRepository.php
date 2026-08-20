<?php

namespace App\Repositories\Eloquent;

use App\Models\WorkspacePlanCatalogPricingChange;
use App\Repositories\Contracts\WorkspacePlanCatalogPricingChangeRepository;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-004 Amendment 2 §8) — deliberately no update() method.
 */
class EloquentWorkspacePlanCatalogPricingChangeRepository extends EloquentBaseRepository implements WorkspacePlanCatalogPricingChangeRepository
{
    public function __construct(WorkspacePlanCatalogPricingChange $pricingChange)
    {
        parent::__construct($pricingChange);
    }

    public function create(array $attributes): WorkspacePlanCatalogPricingChange
    {
        /** @var WorkspacePlanCatalogPricingChange $pricingChange */
        $pricingChange = $this->make($attributes);
        $pricingChange->save();

        return $pricingChange;
    }

    public function forCatalog(int $catalogId): Collection
    {
        return $this->query()
            ->where('workspace_plan_catalog_id', $catalogId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
