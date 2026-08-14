<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager's decision/capacity algorithm (RFC-004 §14
 * step 3, §17) when a Workspace has no workspace_plan_assignments row at
 * all — the `workspace_plan_unassigned` stable denial key.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class WorkspacePlanUnassignedException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] has no plan assignment.");
    }
}
