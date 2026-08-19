<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()
 * (RFC-004 Amendment 1 §9 step 4) when the caller-supplied payment evidence
 * is structurally absent — an empty idempotency key, an empty provider
 * reference, or a non-positive slot delta. No database write of any kind
 * occurs before this check.
 *
 * Carries only a numeric Workspace identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class InvalidPaymentAllocationEvidenceException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Payment allocation evidence for Workspace [{$workspaceId}] is invalid.");
    }
}
