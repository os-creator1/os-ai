<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()
 * (RFC-004 Amendment 1 §9 step 7) when a verified payment record is
 * presented against a complimentary Workspace assignment — a complimentary
 * Workspace is, by definition, not being charged, so a paid-looking
 * transition row against one is refused rather than silently allocated.
 * setAdditionalBusinessSlots() itself is unaffected by this guard.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class ComplimentaryWorkspaceCannotAllocatePaidSlotsException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] is complimentary and cannot allocate additional Business slots from a verified payment.");
    }
}
