<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by every Milestone 2 mutation that requires an active Workspace
 * (RFC-003 Milestone 2 domain contract, corrected report §1/§2) except
 * reactivateWorkspace() and a duplicate deactivateWorkspace() call, which
 * are the two authorized exceptions to this rule.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class InactiveWorkspaceMutationException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct(
            "Workspace [{$workspaceId}] is inactive and cannot be mutated."
        );
    }
}
