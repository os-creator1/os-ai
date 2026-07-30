<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by WorkspaceBackfillV1 when a legacy customer_id group already
 * references more than one distinct Workspace (RFC-003 §10.4 step 4) — a
 * pre-existing inconsistency the backfill refuses to silently resolve.
 * Carries only numeric identifiers, never customer name/email/company data.
 */
class WorkspaceBackfillConflictException extends RuntimeException
{
    /**
     * @param  array<int, int>  $conflictingWorkspaceIds
     */
    public function __construct(
        public readonly int $customerId,
        public readonly array $conflictingWorkspaceIds
    ) {
        parent::__construct(sprintf(
            'Business backfill found customer_id [%d] already referencing multiple Workspaces [%s].',
            $customerId,
            implode(', ', $conflictingWorkspaceIds)
        ));
    }
}
