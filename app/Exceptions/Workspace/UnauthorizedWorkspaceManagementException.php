<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by WorkspaceManager's owner/owner-or-active-admin assertions
 * (RFC-003 Milestone 2 domain contract, corrected report §8) when the
 * acting user does not hold the management authority a mutation requires.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class UnauthorizedWorkspaceManagementException extends RuntimeException
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $workspaceId,
    ) {
        parent::__construct(
            "User [{$actorUserId}] is not authorized to manage Workspace [{$workspaceId}]."
        );
    }
}
