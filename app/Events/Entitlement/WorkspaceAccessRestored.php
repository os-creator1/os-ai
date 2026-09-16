<?php

namespace App\Events\Entitlement;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contract 03 §10 (Slice 4) — a Workspace is confirmed clean Active again:
 * every lifecycle timestamp was cleared in one write (Blueprint §27's
 * "immediate unlock on confirmed payment").
 *
 * One event covers both an early trial conversion and a recovery out of
 * Grace/Locked, because one writer covers both (§6 case E).
 */
class WorkspaceAccessRestored implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $actorUserId,
        public readonly ?string $reason,
    ) {
    }
}
