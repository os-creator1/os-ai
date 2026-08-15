<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessFeatureToggleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $workspaceId,
        public readonly string $featureKey,
        public readonly bool $disabled,
        public readonly int $actorUserId,
    ) {
    }
}
