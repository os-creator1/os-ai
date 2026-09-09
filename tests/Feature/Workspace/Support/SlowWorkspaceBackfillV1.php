<?php

namespace Tests\Feature\Workspace\Support;

use App\Library\Workspace\Migration\WorkspaceBackfillV1;

/**
 * Test-only subclass that holds the users-row lock open for a controlled
 * duration after acquiring it (lockOwnerRow() is protected exactly for
 * this), used by the real cross-process concurrency test to prove a
 * second concurrent attempt genuinely blocks rather than completing
 * sequentially by coincidence.
 */
class SlowWorkspaceBackfillV1 extends WorkspaceBackfillV1
{
    public function __construct(private readonly float $holdSeconds)
    {
    }

    protected function lockOwnerRow(int $customerId): ?object
    {
        $row = parent::lockOwnerRow($customerId);

        if ($row !== null) {
            // The synchronisation barrier the parent test waits on.
            // Emitted AFTER the lock is genuinely acquired and BEFORE the
            // hold begins, so the parent starts the racing process at the
            // one moment contention is guaranteed, rather than guessing a
            // duration and hoping the child had booted. Flushed
            // immediately: an unflushed buffer would defeat the barrier.
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);

            usleep((int) ($this->holdSeconds * 1_000_000));
        }

        return $row;
    }
}
