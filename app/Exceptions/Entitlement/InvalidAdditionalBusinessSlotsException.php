<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager (RFC-004 §17/§17.1) when an
 * additionalBusinessSlots argument is outside the valid bound for the
 * tier involved — an increase beyond what the catalog row offers
 * (business_slot_max − business_slot_included; none on the corrected
 * Core/Growth rows, RFC-004 §33.2), a negative value, or any non-zero
 * value for Agency (which has no additional-slot concept).
 *
 * Carries only the offending numeric value and tier identity — never
 * Customer, User or Business names, company, email, phone, or address.
 */
class InvalidAdditionalBusinessSlotsException extends RuntimeException
{
    public function __construct(
        public readonly string $tier,
        public readonly int $additionalBusinessSlots,
    ) {
        parent::__construct("Additional Business slots [{$additionalBusinessSlots}] is invalid for tier [{$tier}].");
    }
}
