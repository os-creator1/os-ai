<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown at the WorkspaceManager boundary (RFC-003 Milestone 2 domain
 * contract, corrected report §10) in place of the generic
 * ModelNotFoundException, whenever a Workspace cannot be found under lock.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class WorkspaceNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] does not exist.");
    }
}
