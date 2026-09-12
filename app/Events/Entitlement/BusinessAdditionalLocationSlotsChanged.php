<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Customer Experience Slice 1A (RFC-004 §33.4, §21 discipline) — one
 * Business's additional-location allocation changed. Durably audited as an
 * `additional_location_slots_changed` transition in the same transaction.
 */
class BusinessAdditionalLocationSlotsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $businessId,
        public readonly int $fromAdditionalLocationSlots,
        public readonly int $toAdditionalLocationSlots,
        public readonly ?int $actorUserId,
    ) {
    }
}
