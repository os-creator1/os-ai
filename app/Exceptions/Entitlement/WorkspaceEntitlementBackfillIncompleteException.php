<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by WorkspaceEntitlementBackfillV1 when, after every Workspace has
 * been processed, a non-zero count of Workspaces still lack a
 * workspace_plan_assignments row (RFC-004 §25.2) — a failed run must never
 * be reported as a silent success, and no raw database exception
 * substitutes for this check.
 */
class WorkspaceEntitlementBackfillIncompleteException extends RuntimeException
{
    public function __construct(public readonly int $remainingUnassignedCount)
    {
        parent::__construct(
            "Workspace entitlement backfill incomplete: {$remainingUnassignedCount} Workspace(s) still have no workspace_plan_assignments row."
        );
    }
}
