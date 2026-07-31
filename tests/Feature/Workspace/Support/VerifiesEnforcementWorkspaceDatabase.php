<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepares and guards the deterministic post-migration-5/pre-migration-6
 * schema state every enforcement-migration test (RFC-003 M1B Slice 4B)
 * needs. Runs before EACH test — a full migrate:fresh, then rolling back
 * exactly migration 6 — since these tests exercise migration 6's
 * up()/down() directly and must never see another test's leftover schema
 * state. Every migration command here targets the child process's own
 * default connection, which is the generated enforcement database itself
 * (its DB_DATABASE was set before this process even booted) — never
 * ultimatesms_testing.
 */
trait VerifiesEnforcementWorkspaceDatabase
{
    /**
     * Migration 6 is no longer guaranteed to be the newest migration in the
     * chain (RFC-003 Milestone 2 adds workspace_transitions after it), so a
     * fixed `--step=1` rollback can no longer be trusted to undo exactly
     * this migration. rollbackToPostMigrationFive() below computes the
     * exact step count instead, by name, so this stays correct regardless
     * of how many later migrations exist.
     */
    private const MIGRATION_SIX_NAME = '2026_07_30_120006_enforce_business_workspace_constraint';

    protected function prepareEnforcementDatabase(): void
    {
        $expected = getenv('EXPECTED_TEST_DATABASE');

        if ($expected === false || $expected === '') {
            throw new RuntimeException(
                'EXPECTED_TEST_DATABASE is not set — refusing to run an enforcement Workspace test.'
            );
        }

        if (! TemporaryTestDatabase::isValidEnforcementName($expected)) {
            throw new RuntimeException(
                "EXPECTED_TEST_DATABASE [{$expected}] does not match the strict enforcement temporary-database prefix."
            );
        }

        $resolved = DB::connection()->getDatabaseName();

        if ($resolved !== $expected) {
            throw new RuntimeException(
                "Refusing to run: resolved database is [{$resolved}], expected [{$expected}]."
            );
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->rollbackToPostMigrationFive();

        $this->verifyPreEnforcementSchema();
    }

    /**
     * Rolls back every migration from the newest down through migration 6
     * (inclusive), landing on the exact post-migration-5 schema regardless
     * of how many migrations were added after migration 6. The step count
     * is computed from the migrations table itself — never hard-coded —
     * since a hard-coded --step=1 silently stops being "migration 6" the
     * moment a later migration exists.
     */
    private function rollbackToPostMigrationFive(): void
    {
        $migrationSix = DB::table('migrations')->where('migration', self::MIGRATION_SIX_NAME)->first();

        if ($migrationSix === null) {
            throw new RuntimeException(
                'Refusing to prepare the enforcement database: migration ['
                . self::MIGRATION_SIX_NAME . '] is not applied.'
            );
        }

        $migrationSixCount = DB::table('migrations')->where('migration', self::MIGRATION_SIX_NAME)->count();

        if ($migrationSixCount !== 1) {
            throw new RuntimeException(
                'Refusing to prepare the enforcement database: migration [' . self::MIGRATION_SIX_NAME
                . "] appears {$migrationSixCount} times in the migrations table."
            );
        }

        $stepCount = DB::table('migrations')->where('id', '>=', $migrationSix->id)->count();

        if ($stepCount < 1) {
            throw new RuntimeException(
                'Refusing to prepare the enforcement database: computed an invalid rollback step count.'
            );
        }

        Artisan::call('migrate:rollback', ['--step' => $stepCount, '--force' => true]);
    }

    private function verifyPreEnforcementSchema(): void
    {
        $migrationFiveApplied = DB::table('migrations')
            ->where('migration', 'like', '%backfill_business_workspaces%')
            ->exists();

        if (! $migrationFiveApplied) {
            throw new RuntimeException(
                'Refusing to run: backfill_business_workspaces is not applied after rollback.'
            );
        }

        $migrationSixApplied = DB::table('migrations')
            ->where('migration', 'like', '%enforce_business_workspace_constraint%')
            ->exists();

        if ($migrationSixApplied) {
            throw new RuntimeException(
                'Refusing to run: enforce_business_workspace_constraint is still applied after rollback.'
            );
        }

        $newerMigrationsCount = DB::table('migrations')
            ->where('migration', '>', self::MIGRATION_SIX_NAME)
            ->count();

        if ($newerMigrationsCount > 0) {
            throw new RuntimeException(
                "Refusing to run: {$newerMigrationsCount} migration(s) newer than "
                . 'enforce_business_workspace_constraint are still applied after rollback.'
            );
        }

        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'workspace_id'"
        );

        if ($column === null || $column->IS_NULLABLE !== 'YES') {
            throw new RuntimeException(
                'Refusing to run: businesses.workspace_id is not nullable after rollback.'
            );
        }

        $foreignKeyCount = DB::selectOne(
            "SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id' AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'"
        )->total;

        $indexCount = DB::selectOne(
            "SELECT COUNT(DISTINCT INDEX_NAME) AS total FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND INDEX_NAME IN ('businesses_workspace_id_index', 'businesses_workspace_id_status_index')"
        )->total;

        if ($foreignKeyCount > 0 || $indexCount > 0) {
            throw new RuntimeException(
                'Refusing to run: final Workspace FK/index constraints already exist after rollback.'
            );
        }
    }
}
