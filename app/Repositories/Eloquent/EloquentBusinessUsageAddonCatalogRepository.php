<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageAddonCatalog;
use App\Repositories\Contracts\BusinessUsageAddonCatalogRepository;

class EloquentBusinessUsageAddonCatalogRepository extends EloquentBaseRepository implements BusinessUsageAddonCatalogRepository
{
    public function __construct(BusinessUsageAddonCatalog $catalog)
    {
        parent::__construct($catalog);
    }

    public function findActiveByAddonKey(string $addonKey): ?BusinessUsageAddonCatalog
    {
        return $this->query()
            ->where('addon_key', $addonKey)
            ->where('is_active', true)
            ->first();
    }

    public function findByAddonKey(string $addonKey): ?BusinessUsageAddonCatalog
    {
        return $this->query()->where('addon_key', $addonKey)->first();
    }
}
