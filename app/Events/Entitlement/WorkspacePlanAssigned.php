<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspacePlanAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $workspacePlanCatalogId,
        public readonly ?int $actorUserId,
    ) {
    }
}
