<?php

namespace App\Console\Commands;

use App\Exceptions\Entitlement\WorkspaceEntitlementBackfillIncompleteException;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
use Illuminate\Console\Command;

/**
 * Thin operator-facing wrapper around WorkspaceEntitlementBackfillV1
 * (RFC-004 §25.2). Contains no backfill algorithm of its own — it only
 * invokes the action, reports its result, and translates its one typed
 * failure mode into a non-zero exit code. Safe to run repeatedly; the
 * action itself is idempotent.
 */
class BackfillWorkspaceEntitlementsCommand extends Command
{
    protected $signature = 'workspaces:backfill-entitlements';

    protected $description = 'Assign every pre-existing Workspace a complimentary Core plan entitlement (RFC-004 §25)';

    public function handle(WorkspaceEntitlementBackfillV1 $backfill): int
    {
        try {
            $result = $backfill->run();
        } catch (WorkspaceEntitlementBackfillIncompleteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Workspace entitlement backfill complete.');
        $this->line("workspaces processed={$result->workspacesProcessed}");
        $this->line("assignments created={$result->assignmentsCreated}");
        $this->line("remaining unassigned count={$result->remainingUnassignedCount}");

        return self::SUCCESS;
    }
}
