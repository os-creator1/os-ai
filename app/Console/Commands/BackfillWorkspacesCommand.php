<?php

namespace App\Console\Commands;

use App\Exceptions\Workspace\WorkspaceBackfillConflictException;
use App\Exceptions\Workspace\WorkspaceBackfillIncompleteException;
use App\Library\Workspace\Migration\WorkspaceBackfillV1;
use Illuminate\Console\Command;

/**
 * Thin operator-facing wrapper around WorkspaceBackfillV1 (RFC-003 §10.3).
 * Contains no backfill algorithm of its own — it only invokes the action,
 * reports its result, and translates its two typed failure modes into a
 * non-zero exit code. Safe to run repeatedly; the action itself is
 * idempotent (RFC-003 §10.4).
 */
class BackfillWorkspacesCommand extends Command
{
    protected $signature = 'workspaces:backfill';

    protected $description = 'Assign every pre-existing null-workspace_id Business to a Workspace (RFC-003 §10)';

    public function handle(WorkspaceBackfillV1 $backfill): int
    {
        try {
            $result = $backfill->run();
        } catch (WorkspaceBackfillConflictException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (WorkspaceBackfillIncompleteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Workspace backfill complete.');
        $this->line("groups processed={$result->groupsProcessed}");
        $this->line("workspaces created={$result->workspacesCreated}");
        $this->line("workspaces reused={$result->workspacesReused}");
        $this->line("businesses assigned={$result->businessesAssigned}");
        $this->line("remaining null count={$result->remainingNullCount}");

        return self::SUCCESS;
    }
}
