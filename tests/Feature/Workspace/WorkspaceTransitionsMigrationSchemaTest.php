<?php

namespace Tests\Feature\Workspace;

use Closure;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
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
 * Every operation below targets the generated named connection explicitly
 * — the default connection (and therefore the primary database) is
 * touched only for the deliberately-scoped duration documented below, so
 * this runs safely inside the normal shared suite with no dedicated
 * group/runner needed.
 *
 * The down()/up() portion (RFC-004 M1 regression-compatibility fix)
 * targets this exact migration file directly — require the file once and
 * call the same returned instance's own up()/down() methods — rather than
 * through `migrate:rollback`'s --step/--batch auto-selection. Verified
 * against this installed Laravel 12 framework's actual source
 * (vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php,
 * RollbackCommand.php, BaseCommand.php): `getMigrationsForRollback()`
 * selects which migrations to roll back purely by `--step` (most recently
 * applied N) or `--batch` (a whole batch number) — `--path` only narrows
 * which of that already-selected file set is resolvable via
 * `getMigrationFiles($paths)`, it never influences the selection itself.
 * Once RFC-004 M1's own later migrations exist, this migration is no
 * longer the globally latest one, so no `--step` value can isolate it
 * alone without also rolling back every later migration (and any such
 * value would have to grow every time a further migration is added,
 * which is exactly the hardcoded-count dependency this fix must avoid),
 * and `--batch` would roll back its entire original batch (migrations
 * 1-7 together), not this file alone. Calling the migration's own
 * up()/down() directly instead — combined with
 * DatabaseMigrationRepository's own public log()/delete() methods (the
 * exact calls Migrator itself makes internally for its migrations-table
 * bookkeeping) — reproduces the identical up()/down() effect for just
 * this one migration, independent of how many further migrations exist
 * now or are added later.
 */
class WorkspaceTransitionsMigrationSchemaTest extends TestCase
{
    private const MIGRATION_NAME = '2026_07_31_120001_create_workspace_transitions_table';

    private const MIGRATION_PATH = 'migrations/2026_07_31_120001_create_workspace_transitions_table.php';

    public function test_migration_up_down_up_cycle_is_clean_on_an_isolated_database(): void
    {
        TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $connectionName) {
            // Up: the full chain from an empty database, proving the
            // complete current migration chain — including every later
            // RFC-004 migration — applies cleanly.
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            $this->assertTrue($this->tableExists($connectionName, $databaseName));
            $this->assertMigrationRowApplied($connectionName, true);

            $repository = $this->migrationRepository($connectionName);
            $migration = require database_path(self::MIGRATION_PATH);

            // Down: this exact migration file's own down() only, run
            // against the isolated connection (Schema:: is uncached on
            // this facade — see Schema::$cached = false — so it always
            // resolves against the current default connection at call
            // time, exactly how Migrator::usingConnection() itself makes
            // --database work for the ordinary Artisan commands above).
            $this->withDefaultConnection($connectionName, fn () => $migration->down());
            $repository->delete((object) ['migration' => self::MIGRATION_NAME]);

            $this->assertFalse($this->tableExists($connectionName, $databaseName));
            $this->assertMigrationRowApplied($connectionName, false);

            // Up again: the same migration instance's own up() only — a
            // second up() after down() must succeed cleanly.
            $this->withDefaultConnection($connectionName, fn () => $migration->up());
            $repository->log(self::MIGRATION_NAME, $repository->getNextBatchNumber());

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

    /**
     * Runs $callback with the default connection temporarily swapped to
     * $connectionName — mirroring Migrator::usingConnection()'s exact
     * swap-then-restore discipline — so the Schema:: facade calls inside
     * the migration's up()/down() resolve against the isolated database
     * rather than the primary one.
     */
    private function withDefaultConnection(string $connectionName, Closure $callback): void
    {
        $previousDefaultConnection = DB::getDefaultConnection();

        DB::setDefaultConnection($connectionName);

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($previousDefaultConnection);
        }
    }

    private function migrationRepository(string $connectionName): DatabaseMigrationRepository
    {
        /** @var DatabaseMigrationRepository $repository */
        $repository = app('migration.repository');
        $repository->setSource($connectionName);

        return $repository;
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
