<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspacePlanAssignment;

/**
 * Plain data-access contract — no effective-entitlement, slot-capacity, or
 * billing-state decision logic. Those are exclusively EntitlementManager's
 * responsibility (M2, RFC-004 §14/§17/§18/§20).
 */
interface WorkspacePlanAssignmentRepository extends BaseRepository
{
    public function findByWorkspaceId(int $workspaceId): ?WorkspacePlanAssignment;

    public function create(array $attributes): WorkspacePlanAssignment;

    /**
     * Plain data-access mutation — no pricing-invariant/business-rule
     * logic here. Every invariant (slot-value bounds, status transitions,
     * complimentary provenance) is enforced exclusively by
     * EntitlementManager before it calls this method (M2, RFC-004 §20).
     */
    public function update(WorkspacePlanAssignment $assignment, array $attributes): WorkspacePlanAssignment;

    /**
     * A locking/current read — deliberately NOT an ordinary
     * consistent-snapshot exists() query. Reports whether at least one
     * non-complimentary assignment currently references this catalog row,
     * guaranteeing visibility of a row committed on another connection
     * before the caller's catalog-row lock was acquired, even under
     * MySQL/InnoDB REPEATABLE READ. Required by
     * EntitlementManager::updateCatalogPricing()'s clearing guard (M2,
     * RFC-004 §12.5). No entitlement/business logic here — existence only.
     */
    public function hasNonComplimentaryForCatalogForUpdate(int $catalogId): bool;
}
