<?php

namespace App\DTO\Entitlement;

/**
 * Customer Experience Slice 1A (RFC-004 §33, contract §7.3, §7.5.1) — one
 * Business's physical-location capacity, explained rather than only
 * exception-shaped. Produced only by EntitlementManager; UI and tests never
 * re-derive the arithmetic.
 *
 * The four capacity kinds are each separately readable without inference:
 * included (plan), additional (allocated 4th/5th, reusable), grandfathered
 * (complimentary excess, never reusable) and archived (consumes nothing).
 */
final readonly class LocationSlotCapacityDecision
{
    public function __construct(
        public int $activeLocationCount,
        public int $archivedLocationCount,
        public int $includedSlots,
        public int $additionalSlotsAllocated,
        public int $grandfatheredSlots,
        /** Hard ceiling for the tier (6+ needs Agency); null when unlimited. */
        public ?int $maximumSlots,
        /** included + additional + grandfathered; null when unlimited. */
        public ?int $effectiveCapacity,
        public bool $unlimited,
        public bool $allowed,
        public ?string $denialReason,
    ) {
    }

    /** Active locations that may still be added right now. */
    public function remaining(): ?int
    {
        if ($this->unlimited) {
            return null;
        }

        if (! $this->allowed) {
            return 0;
        }

        if ($this->effectiveCapacity === null) {
            // No plan to read capacity from: only the first location is certain.
            return 1;
        }

        return max(0, min($this->effectiveCapacity, (int) $this->maximumSlots) - $this->activeLocationCount);
    }

    public function isOverCapacity(): bool
    {
        return ! $this->unlimited
            && $this->activeLocationCount > $this->includedSlots + $this->additionalSlotsAllocated;
    }
}
