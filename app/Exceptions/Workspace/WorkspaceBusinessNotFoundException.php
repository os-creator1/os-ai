<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown at the WorkspaceManager boundary (RFC-003 Milestone 2 domain
 * contract, corrected report §10) in place of the generic
 * ModelNotFoundException, whenever a Business cannot be found under lock
 * inside a Workspace-orchestration method (e.g. reassignBusiness()).
 *
 * Carries only a numeric Business identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class WorkspaceBusinessNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{$businessId}] does not exist.");
    }
}
