<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guards every historical M1A test (RFC-003 M1B Slice 4A) against ever
 * running against the wrong database or the wrong schema shape. Runs
 * before each test — not once per class — so no test ordering assumption
 * is required. Never migrates anything; verification only.
 */
trait VerifiesHistoricalWorkspaceSchema
{
    protected function verifyHistoricalWorkspaceSchema(): void
    {
        $expected = getenv('EXPECTED_TEST_DATABASE');

        if ($expected === false || $expected === '') {
            throw new RuntimeException(
                'EXPECTED_TEST_DATABASE is not set — refusing to run a historical Workspace test.'
            );
        }

        if (! TemporaryTestDatabase::isValidHistoricalName($expected)) {
            throw new RuntimeException(
                "EXPECTED_TEST_DATABASE [{$expected}] does not match the strict historical temporary-database prefix."
            );
        }

        $resolved = DB::connection()->getDatabaseName();

        if ($resolved !== $expected) {
            throw new RuntimeException(
                "Refusing to run: resolved database is [{$resolved}], expected [{$expected}]."
            );
        }

        $this->verifyWorkspaceIdIsNullable();
        $this->verifyFinalConstraintsAreAbsent();
        $this->verifyMigrationSixIsAbsent();
    }

    private function verifyWorkspaceIdIsNullable(): void
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'workspace_id'"
        );

        if ($column === null || $column->IS_NULLABLE !== 'YES') {
            throw new RuntimeException(
                'Refusing to run: businesses.workspace_id is not nullable in the resolved historical database.'
            );
        }
    }

    private function verifyFinalConstraintsAreAbsent(): void
    {
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
                'Refusing to run: final Workspace FK/index constraints already exist in the resolved historical database.'
            );
        }
    }

    private function verifyMigrationSixIsAbsent(): void
    {
        $applied = DB::table('migrations')
            ->where('migration', 'like', '%enforce_business_workspace_constraint%')
            ->exists();

        if ($applied) {
            throw new RuntimeException(
                'Refusing to run: enforce_business_workspace_constraint has already been applied to the resolved historical database.'
            );
        }
    }
}
