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

    /**
     * Row-locking variant of findById(), for callers that must hold the
     * row lock for the duration of a transaction (M2, RFC-004 §12.5/§20 —
     * the canonical per-catalog-row serialization point).
     */
    public function findForUpdate(int $id): ?WorkspacePlanCatalog;

    public function create(array $attributes): WorkspacePlanCatalog;

    /**
     * Plain data-access mutation — no pricing-invariant/business-rule
     * logic here. Every invariant (both-null-or-both-populated pairing,
     * in-use protection) is enforced exclusively by EntitlementManager
     * before it calls this method (M2, RFC-004 §12.5).
     */
    public function update(WorkspacePlanCatalog $catalog, array $attributes): WorkspacePlanCatalog;
}
