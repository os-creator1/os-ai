<?php

declare(strict_types=1);

namespace App\Library\Entitlement\Migration;

/**
 * Immutable outcome of a single WorkspaceEntitlementBackfillV1::run() call.
 * Only ever constructed after every Workspace has been processed and the
 * final global unassigned-count check has passed (RFC-004 §25.2) — a
 * WorkspaceEntitlementBackfillResult therefore always has
 * remainingUnassignedCount === 0; a non-zero count is reported via
 * WorkspaceEntitlementBackfillIncompleteException instead of a result with
 * a false/failed flag.
 */
final readonly class WorkspaceEntitlementBackfillResult
{
    public function __construct(
        public int $workspacesProcessed,
        public int $assignmentsCreated,
        public int $remainingUnassignedCount,
    ) {
    }
}
