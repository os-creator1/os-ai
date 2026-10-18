<?php

namespace App\Events\Business;

use Illuminate\Foundation\Events\Dispatchable;

/** A Business moved between active and inactive. Raised only by PlatformOwnerAccountActions::changeBusinessStatus, the single write authority for that change. */
final class BusinessStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly int $actorUserId,
    ) {
    }
}
