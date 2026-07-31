<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspaceReactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $actorUserId,
    ) {
    }
}
