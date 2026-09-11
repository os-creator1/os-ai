<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3) — a Core/Growth Business has
 * used every active-location slot it holds, but its tier still allows more
 * with an additional-location allocation (the 4th or 5th location). The
 * `location_slot_allocation_required` stable denial key.
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class LocationSlotAllocationRequiredException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{$businessId}] needs an additional-location allocation before another location can be active.");
    }
}
