<?php

namespace App\Library\Entitlement;

final readonly class BusinessSlotCapacityDecision
{
    public function __construct(
        public int $currentBusinessCount,
        public int $includedSlots,
        public int $additionalSlotsAllocated,
        public ?int $effectiveCapacity,
        public bool $unlimited,
        public bool $allowed,
        public ?string $denialReason,
    ) {
    }
}
