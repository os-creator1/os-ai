<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;

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

    public function create(array $attributes): WorkspacePlanCatalog
    {
        /** @var WorkspacePlanCatalog $catalog */
        $catalog = $this->make($attributes);
        $catalog->save();

        return $catalog;
    }
}
