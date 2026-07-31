<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown at the WorkspaceManager boundary (RFC-003 Milestone 2 domain
 * contract, corrected report §10) in place of the generic
 * ModelNotFoundException, whenever a WorkspaceMembership cannot be found
 * for a given (workspace, user) pair.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class WorkspaceMembershipNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $userId,
    ) {
        parent::__construct(
            "WorkspaceMembership does not exist for Workspace [{$workspaceId}], User [{$userId}]."
        );
    }
}
