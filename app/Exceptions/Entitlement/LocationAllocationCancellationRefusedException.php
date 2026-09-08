<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3/§7.3a).
 * Thrown when cancelling a paid additional-location allocation would leave the Business with more active locations than the post-cancellation capacity permits (§7.3a rule 6). A refused cancellation is a complete no-op.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class LocationAllocationCancellationRefusedException extends RuntimeException
{
    public function __construct(public readonly int $businessId, public readonly int $activeCount, public readonly int $capacityAfter)
    {
        parent::__construct("Business [{\$businessId}] cannot cancel a paid physical-location allocation while {\$activeCount} locations are active and post-cancellation capacity is {\$capacityAfter}.");
    }
}
