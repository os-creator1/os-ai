<?php

namespace App\Repositories\Contracts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\WorkspacePlanCatalog;

/**
 * Plain data-access contract — no slot/pricing decision logic. Reading
 * business_slot_included/business_slot_max/unlimited_business_slots/
 * additional_business_slot_price_ratio and applying them is exclusively
 * EntitlementManager's responsibility (M2, RFC-004 §12.1/§20).
 */
interface WorkspacePlanCatalogRepository extends BaseRepository
{
    public function findById(int $id): ?WorkspacePlanCatalog;

    public function findByTier(WorkspacePlanTier $tier): ?WorkspacePlanCatalog;

    public function create(array $attributes): WorkspacePlanCatalog;
}
