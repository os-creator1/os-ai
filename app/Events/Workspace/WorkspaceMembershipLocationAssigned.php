<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 02 (Location ACL Foundation) §10 — the
 * Location-equivalent of WorkspaceMembershipBusinessAssigned, mirrored
 * exactly. Not dispatched anywhere in this slice: like
 * WorkspaceMembershipBusinessAssigned, dispatch requires actor context
 * (actorUserId) that only a manager-layer caller has, and this slice adds
 * no such caller — WorkspaceMembershipLocationRepository's methods mirror
 * WorkspaceMembershipBusinessRepository's own actor-less signatures
 * exactly, and dispatch nothing themselves either. Available for
 * Contract 08B's consumer wiring to dispatch once it adds the
 * manager-layer method(s) that call this repository with a real actor.
 */
class WorkspaceMembershipLocationAssigned implements ShouldDispatchAfterCommit
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
