<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::assertCanCreateAnotherBusiness() (RFC-004
 * §17) when a Workspace is already at its hard Business-slot maximum
 * (business_slot_max for Core/Growth) — no further allocation can raise
 * this ceiling. The `business_slot_limit_exceeded` stable denial key.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class BusinessSlotLimitExceededException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] is at its maximum Business slot capacity.");
    }
}
