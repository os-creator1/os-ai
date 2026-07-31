<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspaceMembershipBusinessUnassigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $membershipId,
        public readonly int $workspaceId,
        public readonly int $businessId,
        public readonly int $actorUserId,
    ) {
    }
}
