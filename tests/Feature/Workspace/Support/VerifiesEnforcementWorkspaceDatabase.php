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
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $this->verifyPreEnforcementSchema();
    }

    private function verifyPreEnforcementSchema(): void
    {
        $migrationSixApplied = DB::table('migrations')
            ->where('migration', 'like', '%enforce_business_workspace_constraint%')
            ->exists();

        if ($migrationSixApplied) {
            throw new RuntimeException(
                'Refusing to run: enforce_business_workspace_constraint is still applied after rollback.'
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
