<?php

namespace App\Library\Entitlement;

/**
 * Customer Experience Slice 1A — the PHYSICAL-LOCATION capacity decision
 * for one Business (contract §7.3, §7.5.1).
 *
 * Deliberately a sibling of BusinessSlotCapacityDecision, never a
 * replacement: Business/client-account capacity and physical-location
 * capacity are separate concerns and must never be conflated again
 * (RFC-004 §33.1).
 *
 * The four capacity kinds of §7.5.1 are each separately readable here,
 * without inference:
 *
 *   included    — from the tier (3 on Core/Growth)
 *   paid        — subscribed 4th/5th allocations, reusable (§7.3a rule 4)
 *   grandfather — complimentary excess, NOT transferable (§7.5.3)
 *   archived    — not represented, because archived rows consume nothing
 */
final readonly class LocationSlotCapacityDecision
{
    public function __construct(
        public int $activeLocationCount,
        public int $includedSlots,
        public int $additionalSlotsAllocated,
        public int $grandfatheredSlots,
        public ?int $effectiveCapacity,
        public ?int $hardMaximum,
        public bool $unlimited,
        public bool $allowed,
        public ?string $denialReason,
    ) {
    }

    /**
     * How many further locations may be made active right now. Null when
     * the tier is unlimited.
     */
    public function remaining(): ?int
    {
        if ($this->unlimited) {
            return null;
        }

        return max(0, ($this->effectiveCapacity ?? 0) - $this->activeLocationCount);
    }
}
