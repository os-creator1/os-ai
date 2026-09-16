<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 02 (Location ACL Foundation) §10 — the
 * Location-equivalent of WorkspaceMembershipBusinessUnassigned, mirrored
 * exactly. See WorkspaceMembershipLocationAssigned's own docblock for why
 * this slice declares but never dispatches it.
 */
class WorkspaceMembershipLocationUnassigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $membershipId,
        public readonly int $workspaceId,
        public readonly int $businessLocationId,
        public readonly int $actorUserId,
    ) {
    }
}
