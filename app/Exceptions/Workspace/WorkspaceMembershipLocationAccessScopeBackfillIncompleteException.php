<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Implementation Contract 02 (Location ACL Foundation) §8, Migration C —
 * thrown when, immediately before the NOT NULL constraint is applied,
 * workspace_memberships.location_access_scope still has a non-zero null
 * count (active or inactive row alike — no is_active filter). Mirrors
 * WorkspaceBackfillIncompleteException's own precedent exactly: a failed
 * backfill must never be silently forced past by the enforcing migration,
 * and no raw database exception substitutes for this explicit check.
 */
class WorkspaceMembershipLocationAccessScopeBackfillIncompleteException extends RuntimeException
{
    public function __construct(public readonly int $remainingNullCount)
    {
        parent::__construct(
            "WorkspaceMembership location_access_scope backfill incomplete: {$remainingNullCount} row(s) still have a null location_access_scope."
        );
    }
}
