<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspaceEntitlementOverrideChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $featureKey,
        public readonly ?string $fromOverrideState,
        public readonly ?string $toOverrideState,
        public readonly int $actorUserId,
    ) {
    }
}
