<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (RFC-004 §33.4) — an additional-location
 * allocation outside what the tier's catalog permits (Core/Growth: from 0 to
 * location_slot_max - location_slot_included; Agency, unlimited: none).
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class InvalidAdditionalLocationSlotsException extends RuntimeException
{
    public function __construct(public readonly string $tier, public readonly int $count)
    {
        parent::__construct("[{$count}] additional locations is not a valid allocation for the [{$tier}] plan.");
    }
}
