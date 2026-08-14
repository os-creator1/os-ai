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

    public function findById(int $id): ?WorkspacePlanCatalog
    {
        return $this->query()->find($id);
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
        ]));
        $catalog->save();

        return $catalog;
    }
}
