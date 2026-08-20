<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()
 * (RFC-004 Amendment 1 §8 case 4, §9 step 5) when a payment idempotency key
 * has already been recorded against a different Workspace, requesting
 * customer, or resulting slot count than this call's own values — a
 * structural mismatch between what was recorded and what is now being
 * claimed, never a time-based staleness window. The entire transaction is
 * rolled back; the original, earlier row is left completely untouched.
 *
 * Carries only the offending idempotency key — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class PaymentAllocationIdempotencyConflictException extends RuntimeException
{
    public function __construct(public readonly string $paymentIdempotencyKey)
    {
        parent::__construct("Payment idempotency key [{$paymentIdempotencyKey}] has already been recorded for a different allocation.");
    }
}
