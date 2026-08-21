<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageAddonCatalog;

interface BusinessUsageAddonCatalogRepository extends BaseRepository
{
    public function findActiveByAddonKey(string $addonKey): ?BusinessUsageAddonCatalog;

    /**
     * Unfiltered lookup — used only by add-on purchase finalization
     * (M4 contract §21), which must resolve fulfillment_mode for an
     * already-created purchase regardless of the catalog row's current
     * is_active value.
     */
    public function findByAddonKey(string $addonKey): ?BusinessUsageAddonCatalog;
}
