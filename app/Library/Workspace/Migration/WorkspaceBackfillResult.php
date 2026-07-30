<?php

declare(strict_types=1);

namespace App\Library\Workspace\Migration;

/**
 * Immutable outcome of a single WorkspaceBackfillV1::run() call. Only ever
 * constructed after every customer_id group has committed and the final
 * global null-count check has passed (RFC-003 §10.4) — a
 * WorkspaceBackfillResult therefore always has remainingNullCount === 0;
 * a non-zero count is reported via WorkspaceBackfillIncompleteException
 * instead of a result with a false/failed flag.
 */
final readonly class WorkspaceBackfillResult
{
    public function __construct(
        public int $groupsProcessed,
        public int $workspacesCreated,
        public int $workspacesReused,
        public int $businessesAssigned,
        public int $remainingNullCount,
    ) {
    }
}
