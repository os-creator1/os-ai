<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager's capacity algorithm (RFC-004 §17/§18) when
 * a Business-count-increasing operation targets a Workspace whose plan
 * assignment status is `inactive` — the `plan_inactive` billing-state gate.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class InactiveWorkspacePlanException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}]'s plan assignment is inactive.");
    }
}
