<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::assertCanCreateAnotherBusiness() (RFC-004
 * §17) when a Core/Growth Workspace is at its currently-allocated effective
 * capacity but has not yet reached business_slot_max — an additional paid
 * slot allocation would allow the creation, but none has been granted yet.
 * The `business_slot_allocation_required` stable denial key.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class BusinessSlotAllocationRequiredException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] requires an additional Business slot allocation to create another Business.");
    }
}
