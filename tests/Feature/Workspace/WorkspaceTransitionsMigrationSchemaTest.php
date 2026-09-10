<?php

namespace Tests\Feature\Workspace;

use Closure;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;
use Tests\Support\TestDatabaseSafety;
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

    // Post-merge correction (P1 finding on PR #232): TemporaryTestDatabase's
    // own generated-name validator previously checked only the captured
    // base through TestDatabaseSafety, never the complete generated name.
    // A base that is independently safe — including independently under
    // MySQL's 64-character identifier limit — can still, once this class's
    // own `_historical_<pid>_<hex>` / `_enforcement_<pid>_<hex>` suffix is
    // appended, produce a complete name TestDatabaseSafety itself would
    // refuse. Because concurrent_backfill_runner.php trusts
    // isValidHistoricalName() as an authorization path over an
    // externally-supplied EXPECTED_TEST_DATABASE value, that gap was a real
    // bypass of the repository's single database-name authority, not a
    // theoretical one. These tests exercise the public
    // isValidHistoricalName()/isValidEnforcementName() entry points
    // directly — the exact surface both TemporaryTestDatabase's own
    // internal callers and the external runner both go through — with no
    // live database connection required, since both methods are pure
    // string functions.

    /** A syntactically valid 8-hex-character suffix, chosen to contain no
     * decimal digit twice in a way that would make a length count error
     * easy to miss — deliberately unremarkable. */
    private const VALID_HEX = 'deadbeef';

    // 1. Ordinary canonical generated name — the base case, must remain
    // valid after this correction.
    public function test_ordinary_canonical_generated_name_is_valid(): void
    {
        $name = 'ultimatesms_testing_historical_1_' . self::VALID_HEX;

        $this->assertTrue(TemporaryTestDatabase::isValidHistoricalName($name));
    }

    // 2. Ordinary validated-sibling generated name — a real disposable
    // sibling used as the base, must remain valid after this correction.
    public function test_ordinary_validated_sibling_generated_name_is_valid(): void
    {
        $name = 'ultimatesms_testing_lane_x_enforcement_42_' . self::VALID_HEX;

        $this->assertTrue(TemporaryTestDatabase::isValidEnforcementName($name));
    }

    // 3. Maximum accepted complete length — exactly 64 characters, the
    // boundary TestDatabaseSafety itself enforces, must be accepted.
    public function test_maximum_accepted_complete_length_is_valid(): void
    {
        // "_historical_1_" + 8 hex chars = 22 chars. A 42-char base
        // (19-char canonical + "_" + 22 filler chars) brings the complete
        // name to exactly 64.
        $base = 'ultimatesms_testing_' . str_repeat('a', 22);
        $this->assertSame(42, strlen($base), 'Fixture invariant: base must be exactly 42 characters.');

        $name = $base . '_historical_1_' . self::VALID_HEX;
        $this->assertSame(64, strlen($name), 'Fixture invariant: complete name must be exactly 64 characters.');

        $this->assertTrue(TemporaryTestDatabase::isValidHistoricalName($name));
    }

    // 4. One character over the limit — 65 characters, must be refused,
    // even though the base alone (43 characters) independently passes
    // TestDatabaseSafety on its own. This is the direct, minimal
    // reproduction of the P1 finding: before this correction,
    // isValidGeneratedName() checked only the base and would have
    // returned true here.
    public function test_one_character_over_the_limit_is_refused(): void
    {
        $base = 'ultimatesms_testing_' . str_repeat('a', 23);
        $this->assertSame(43, strlen($base), 'Fixture invariant: base must be exactly 43 characters.');
        $this->assertTrue(
            TestDatabaseSafety::isSafeTestDatabaseName($base),
            'Fixture invariant: the base alone must be independently safe, proving the eventual refusal comes from the complete name, not the base.'
        );

        $name = $base . '_historical_1_' . self::VALID_HEX;
        $this->assertSame(65, strlen($name), 'Fixture invariant: complete name must be exactly 65 characters.');

        $this->assertFalse(TemporaryTestDatabase::isValidHistoricalName($name));
    }

    // 5. Safe base whose added suffix makes the full name unsafe — restated
    // explicitly against isValidEnforcementName() (not just the historical
    // pattern already covered by test 4) with a longer pid, so the same
    // "safe base, unsafe complete name" property is proven on the second
    // purpose-specific pattern this class owns, not only the first.
    public function test_safe_base_whose_added_suffix_makes_the_full_name_unsafe(): void
    {
        $base = 'ultimatesms_testing_' . str_repeat('b', 20);
        $this->assertTrue(
            TestDatabaseSafety::isSafeTestDatabaseName($base),
            'Fixture invariant: the base alone must be independently safe.'
        );

        // "_enforcement_" (13) + a 6-digit pid (6) + "_" (1) + 8 hex (8) = 28.
        // 40-char base + 28 = 68, comfortably over the limit.
        $name = $base . '_enforcement_123456_' . self::VALID_HEX;
        $this->assertGreaterThan(64, strlen($name), 'Fixture invariant: complete name must exceed the limit.');

        $this->assertFalse(TemporaryTestDatabase::isValidEnforcementName($name));
    }

    // 6. Production-looking captured base — the base itself carries a
    // forbidden suffix segment, so the complete name must be refused via
    // the base-safety check.
    public function test_production_looking_captured_base_is_refused(): void
    {
        $name = 'ultimatesms_testing_prod_historical_1_' . self::VALID_HEX;

        $this->assertFalse(
            TestDatabaseSafety::isSafeTestDatabaseName('ultimatesms_testing_prod'),
            'Fixture invariant: the base alone must already be unsafe.'
        );
        $this->assertFalse(TemporaryTestDatabase::isValidHistoricalName($name));
    }

    // 7. Production-looking complete name — a distinct forbidden segment
    // ("staging") from test 6 ("prod"), asserted against the public
    // isValidEnforcementName() entry point exactly as an external caller
    // such as concurrent_backfill_runner.php would supply a complete,
    // already-assembled EXPECTED_TEST_DATABASE string (never constructed
    // via this class's own generateName()). Proves the complete-name
    // TestDatabaseSafety check this correction adds independently rejects
    // a forbidden segment, not only the base-safety check tests 6 exercises.
    public function test_production_looking_complete_name_is_refused(): void
    {
        $name = 'ultimatesms_testing_staging_enforcement_1_' . self::VALID_HEX;

        $this->assertFalse(TemporaryTestDatabase::isValidEnforcementName($name));
    }

    // 8. Malformed historical/enforcement suffix — shape violations must
    // still be refused exactly as before this correction: 7 hex characters
    // instead of 8, and an enforcement-shaped name fed to the historical
    // checker.
    public function test_malformed_purpose_suffix_is_refused(): void
    {
        $shortHex = 'ultimatesms_testing_historical_1_deadbee';
        $this->assertSame(7, strlen('deadbee'), 'Fixture invariant: exactly 7 hex characters.');
        $this->assertFalse(TemporaryTestDatabase::isValidHistoricalName($shortHex));

        $wrongPurpose = 'ultimatesms_testing_enforcement_1_' . self::VALID_HEX;
        $this->assertFalse(TemporaryTestDatabase::isValidHistoricalName($wrongPurpose));
    }

    // 9. Correct handoff still succeeds — this correction changes only
    // what is refused, never what is accepted for a genuine run.
    // withHistoricalDatabase() end-to-end (create, use, drop) against the
    // real active test database, proven by a real generated name matching
    // isValidHistoricalName() from inside the callback itself.
    public function test_correct_handoff_still_succeeds_end_to_end(): void
    {
        $observedName = null;

        TemporaryTestDatabase::withHistoricalDatabase(function (string $databaseName) use (&$observedName) {
            $observedName = $databaseName;

            $this->assertTrue(TemporaryTestDatabase::isValidHistoricalName($databaseName));
            $this->assertTrue(
                DB::connection('mysql_historical_temp')
                    ->select('select 1 as ok')[0]->ok === 1
            );
        });

        $this->assertNotNull($observedName, 'The callback must have run.');
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
