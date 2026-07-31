<?php

namespace Tests\Feature\Workspace;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A test #25: proves
 * 2026_07_31_120001_create_workspace_transitions_table's up()/down()/up()
 * cycle is clean, using the existing disposable enforcement-database
 * infrastructure (TemporaryTestDatabase::withEnforcementDatabase()) so no
 * schema mutation ever touches the primary ultimatesms_testing database.
 * Every Artisan call below targets the generated named connection
 * explicitly via --database — the default connection (and therefore the
 * primary database) is never touched, so this runs safely inside the
 * normal shared suite with no dedicated group/runner needed.
 */
class WorkspaceTransitionsMigrationSchemaTest extends TestCase
{
    private const MIGRATION_NAME = '2026_07_31_120001_create_workspace_transitions_table';

    public function test_migration_up_down_up_cycle_is_clean_on_an_isolated_database(): void
    {
        TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $connectionName) {
            // Up: the full chain from an empty database, proving the new
            // migration applies cleanly alongside every earlier one.
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            $this->assertTrue($this->tableExists($connectionName, $databaseName));
            $this->assertMigrationRowApplied($connectionName, true);

            // Down: never assume it worked — independently re-verify.
            Artisan::call('migrate:rollback', ['--database' => $connectionName, '--step' => 1, '--force' => true]);

            $this->assertFalse($this->tableExists($connectionName, $databaseName));
            $this->assertMigrationRowApplied($connectionName, false);

            // Up again: a second up() after down() must succeed cleanly.
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            $this->assertTrue($this->tableExists($connectionName, $databaseName));
            $this->assertMigrationRowApplied($connectionName, true);

            $foreignKeyCount = DB::connection($connectionName)
                ->table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', $databaseName)
                ->where('TABLE_NAME', 'workspace_transitions')
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->count();
            $this->assertSame(3, $foreignKeyCount, 'Expected exactly three foreign keys after the second up().');
        });
    }

    private function tableExists(string $connectionName, string $databaseName): bool
    {
        return DB::connection($connectionName)
            ->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $databaseName)
            ->where('TABLE_NAME', 'workspace_transitions')
            ->exists();
    }

    private function assertMigrationRowApplied(string $connectionName, bool $expectedApplied): void
    {
        $applied = DB::connection($connectionName)
            ->table('migrations')
            ->where('migration', self::MIGRATION_NAME)
            ->exists();

        $this->assertSame($expectedApplied, $applied);
    }
}
