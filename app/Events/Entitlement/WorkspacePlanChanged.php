<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkspacePlanChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $fromWorkspacePlanCatalogId,
        public readonly int $toWorkspacePlanCatalogId,
        public readonly int $actorUserId,
    ) {
    }
}
