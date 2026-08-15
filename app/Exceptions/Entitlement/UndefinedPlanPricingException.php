<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager (RFC-004 §12.5) whenever a mutation would
 * represent a non-complimentary (paid) commercial state — an initial
 * assignment, a plan change, an additional-slot increase, or a
 * complimentary-status revoke — against a workspace_plan_catalog row whose
 * price, currency_id, or (when a paid additional slot is involved)
 * additional_business_slot_price_ratio is not yet defined.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class UndefinedPlanPricingException extends RuntimeException
{
    public function __construct(public readonly int $catalogId)
    {
        parent::__construct("Workspace plan catalog [{$catalogId}] does not have complete pricing defined for this mutation.");
    }
}
