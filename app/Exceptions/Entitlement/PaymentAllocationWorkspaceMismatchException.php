<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()
 * (RFC-004 Amendment 1 §7/§9 step 3) when the caller's own verified-payment
 * evidence records a different Workspace than the one being allocated
 * against. No database write of any kind occurs before this check.
 *
 * Carries only numeric Workspace identifiers — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class PaymentAllocationWorkspaceMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $paymentVerifiedForWorkspaceId,
    ) {
        parent::__construct("Payment evidence was verified for Workspace [{$paymentVerifiedForWorkspaceId}], not the target Workspace [{$workspaceId}].");
    }
}
