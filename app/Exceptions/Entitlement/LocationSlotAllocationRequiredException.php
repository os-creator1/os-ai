<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3/§7.3a).
 * Thrown when a Core/Growth Business tries to make a 4th or 5th physical location active without a paid additional-location allocation. The `location_slot_allocation_required` stable denial key.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class LocationSlotAllocationRequiredException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{\$businessId}] needs an additional physical-location allocation before another location can be active.");
    }
}
