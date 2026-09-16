<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contract 03 §10 (Slice 4) — a Workspace's Grace window elapsed without
 * payment, so normal product access is now locked. The base plan status is
 * still Active: Locked is derived from `locked_at`, not from a fourth
 * WorkspacePlanAssignmentStatus case (Addendum §7).
 *
 * `$actorUserId` is null for the system path (the scheduled sweep).
 */
class WorkspaceLocked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $actorUserId,
        public readonly ?string $reason,
    ) {
    }
}
