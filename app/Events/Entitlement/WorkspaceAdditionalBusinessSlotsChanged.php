<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspaceAdditionalBusinessSlotsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $fromAdditionalBusinessSlots,
        public readonly int $toAdditionalBusinessSlots,
        public readonly ?int $actorUserId,
    ) {
    }
}
