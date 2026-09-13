<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Support\Arr;

class EloquentWorkspacePlanCatalogRepository extends EloquentBaseRepository implements WorkspacePlanCatalogRepository
{
    public function __construct(WorkspacePlanCatalog $catalog)
    {
        parent::__construct($catalog);
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — the catalog is re-read by id both by the controller's own
     * entitlement check and, independently, by the menu/shell's snapshot;
     * this memoizes it for the life of the current request only. update()
     * below invalidates the same key.
     */
    public function findById(int $id): ?WorkspacePlanCatalog
    {
        return $this->rememberForRequest(
            "workspace_plan_catalog:find:{$id}",
            fn () => $this->query()->find($id),
        );
    }

    public function findByTier(WorkspacePlanTier $tier): ?WorkspacePlanCatalog
    {
        return $this->query()->where('tier', $tier->value)->first();
    }

    public function findForUpdate(int $id): ?WorkspacePlanCatalog
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function create(array $attributes): WorkspacePlanCatalog
    {
        /** @var WorkspacePlanCatalog $catalog */
        $catalog = $this->make($attributes);
        $catalog->save();

        return $catalog;
    }

    public function update(WorkspacePlanCatalog $catalog, array $attributes): WorkspacePlanCatalog
    {
        $catalog->fill(Arr::only($attributes, [
            'price',
            'currency_id',
            'is_active',
            'additional_business_slot_price_ratio',
        ]));
        $catalog->save();
        $this->forgetRequestCache("workspace_plan_catalog:find:{$catalog->id}");

        return $catalog;
    }
}
