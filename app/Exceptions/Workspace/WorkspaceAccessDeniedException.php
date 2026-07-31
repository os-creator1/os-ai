<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by WorkspaceManager::assertUserCanAccessBusiness() (RFC-003 §14.1)
 * when the effective-access algorithm resolves to false — direct/Workspace
 * ownership, active membership scope, and the Workspace's own active state
 * have all already been checked by the time this is thrown.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class WorkspaceAccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly int $businessId,
    ) {
        parent::__construct(
            "User [{$userId}] cannot access Business [{$businessId}]."
        );
    }
}
