<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3a rule 6) — an additional-location
 * allocation may only be reduced when the Business's ACTIVE locations fit
 * the reduced capacity. $locationsToArchive says how many must be archived
 * first.
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class LocationAllocationCancellationRefusedException extends RuntimeException
{
    public function __construct(
        public readonly int $businessId,
        public readonly int $activeLocationCount,
        public readonly int $capacityAfter,
        public readonly int $locationsToArchive,
    ) {
        parent::__construct("Business [{$businessId}] has {$activeLocationCount} active locations; archive {$locationsToArchive} before reducing its capacity to {$capacityAfter}.");
    }
}
