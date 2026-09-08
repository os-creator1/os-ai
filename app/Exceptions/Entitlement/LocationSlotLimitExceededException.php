<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3/§7.3a).
 * Thrown when a Core/Growth Business is already at location_slot_max (5) active physical locations. No allocation can raise this ceiling — only Agency can. The `location_slot_limit_exceeded` stable denial key.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class LocationSlotLimitExceededException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{\$businessId}] is at its maximum physical-location capacity.");
    }
}
