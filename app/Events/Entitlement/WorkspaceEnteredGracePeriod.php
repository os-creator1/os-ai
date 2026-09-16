<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contract 03 §10 (Slice 4) — a Workspace's 3-day Grace window opened, either
 * because a renewal failed or because a trial ended without conversion. Both
 * reasons funnel through one event, exactly as they funnel through one column.
 *
 * `$actorUserId` is null for the system path (the scheduled sweep), matching
 * the established null-actor convention for an actor-less write in this
 * subsystem — never a fake administrator id.
 */
class WorkspaceEnteredGracePeriod implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $actorUserId,
        public readonly ?string $reason,
    ) {
    }
}
