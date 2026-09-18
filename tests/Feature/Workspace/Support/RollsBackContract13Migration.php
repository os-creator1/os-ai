<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rolls a disposable database (already fully `migrate:fresh`ed) back
 * exactly one step: Contract 13's own
 * `enforce_workspace_business_one_to_one_constraint` migration, always the
 * newest in the chain. That lands on the authoritative "every migration
 * through Contract 12, Contract 13 not yet applied" schema state —
 * `businesses.workspace_id` carries no unique constraint, so more than one
 * Business per Workspace is exactly what the SCHEMA (never an
 * application-level guard) allows, matching real pre-Contract-13
 * production data.
 *
 * Extracted from WorkspaceBusinessOneToOneEnforcementTest's own
 * (private, near-identical) rollbackContract13Migration(), so every
 * Contract 10/12/location-capacity historical test reuses the one,
 * already-verified rollback technique — never a second, competing
 * implementation of "which migration is Contract 13" or "how far back to
 * roll".
 */
trait RollsBackContract13Migration
{
    private const CONTRACT_13_MIGRATION_NAME = '2026_09_22_100001_enforce_workspace_business_one_to_one_constraint';

    /**
     * Rolls back exactly the Contract 13 migration, computed by name from
     * the migrations table (never a hard-coded --step, which would
     * silently stop meaning "this migration" the moment a later migration
     * is added after it).
     */
    private function rollbackContract13Migration(string $connection): void
    {
        $row = DB::connection($connection)->table('migrations')->where('migration', self::CONTRACT_13_MIGRATION_NAME)->first();

        if ($row === null) {
            throw new RuntimeException(
                'Refusing to prepare the historical database: Contract 13 migration is not applied after migrate:fresh.'
            );
        }

        $stepCount = DB::connection($connection)->table('migrations')->where('id', '>=', $row->id)->count();

        Artisan::call('migrate:rollback', ['--database' => $connection, '--step' => $stepCount, '--force' => true]);

        if (DB::connection($connection)->table('migrations')->where('migration', self::CONTRACT_13_MIGRATION_NAME)->exists()) {
            throw new RuntimeException('Refusing to run: Contract 13 migration is still applied after rollback.');
        }
    }
}
