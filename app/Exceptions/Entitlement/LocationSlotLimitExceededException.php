<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3) — a Core/Growth Business is at
 * its tier's hard location maximum. No allocation can raise it; a 6th active
 * location requires the Agency plan. The `location_slot_limit_exceeded`
 * stable denial key.
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class LocationSlotLimitExceededException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{$businessId}] is at the maximum number of active locations its plan allows.");
    }
}
