<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::assignFirstPlan() (RFC-004 §8) when the
 * target Workspace already has a workspace_plan_assignments row — every
 * Workspace has at most one, and a first assignment is never a change.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class WorkspacePlanAlreadyAssignedException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] already has a plan assignment.");
    }
}
