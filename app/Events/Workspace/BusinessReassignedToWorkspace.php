<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessReassignedToWorkspace implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $previousWorkspaceId,
        public readonly int $newWorkspaceId,
        public readonly int $actorUserId,
    ) {
    }
}
