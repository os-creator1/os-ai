<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by transferOwnership() (RFC-003 Milestone 2 domain contract,
 * corrected report §5/§10) when the supplied new-owner user ID does not
 * reference an existing User row.
 *
 * Carries only a numeric User identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class WorkspaceInvalidIncomingOwnerException extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct("User [{$userId}] cannot be set as Workspace owner.");
    }
}
