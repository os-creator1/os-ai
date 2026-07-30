<?php

namespace Tests\Feature\Workspace\Support;

use App\Library\Workspace\Migration\WorkspaceBackfillV1;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Test-only subclass that injects a controlled failure immediately after
 * the Workspace row is inserted but before the Business-assignment update
 * that follows it in processCustomerGroup() — both inside the same
 * transaction — to prove a mid-group failure leaves no orphan Workspace or
 * partial assignment (RFC-003 §10.4). createWorkspace() is the natural,
 * already-necessary seam between "resolve/create the Workspace" and
 * "assign it"; no production behavior changes.
 */
class FailingWorkspaceBackfillV1 extends WorkspaceBackfillV1
{
    public bool $shouldFail = true;

    protected function createWorkspace(int $customerId, object $userRow, Collection $businesses): int
    {
        $workspaceId = parent::createWorkspace($customerId, $userRow, $businesses);

        if ($this->shouldFail) {
            throw new RuntimeException(
                'Forced failure for testing: after Workspace insert, before Business assignment.'
            );
        }

        return $workspaceId;
    }
}
