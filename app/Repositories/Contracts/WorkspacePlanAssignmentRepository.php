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
}
