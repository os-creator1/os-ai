<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspaceOwnershipTransferred implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $actorUserId,
        public readonly int $previousOwnerUserId,
        public readonly int $newOwnerUserId,
        public readonly string $previousOwnerDisposition,
    ) {
    }
}
