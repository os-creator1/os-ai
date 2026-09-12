<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (RFC-004 §33.4) — a Business's paid
 * additional-location allocation belongs to its current Workspace's plan and
 * billing and never moves with it. A cross-Workspace reassignment of a
 * Business that holds one is refused before anything changes; the allocation
 * is neither cleared nor transferred automatically.
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class LocationAllocationNotPortableException extends RuntimeException
{
    public function __construct(
        public readonly int $businessId,
        public readonly int $additionalLocationSlots,
    ) {
        parent::__construct("Business [{$businessId}] holds {$additionalLocationSlots} additional location slot(s) allocated under its current Workspace; it cannot move to another Workspace while that allocation exists.");
    }
}
