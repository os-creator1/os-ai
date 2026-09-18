<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Base class for tests that must prove Contract 10 / Contract 12 legacy
 * data-migration behaviour, or any other historical pre-Contract-13
 * topology (more than one Business per Workspace), against the REAL
 * migration/service code — never a reimplementation of it, and never by
 * weakening, bypassing, or rolling back Contract 13's
 * `businesses_workspace_id_unique` constraint on the shared
 * `ultimatesms_testing`-family database every other test uses.
 *
 * Every test method in a subclass runs against its OWN disposable
 * `{base}_enforcement_<pid>_<hex>` database (TemporaryTestDatabase),
 * migrated fully fresh and then rolled back exactly one step — Contract
 * 13's own constraint migration (RollsBackContract13Migration) — landing
 * on the authoritative "every migration through Contract 12, Contract 13
 * not yet applied" schema. That disposable database becomes the
 * application's default connection for the duration of the test, so every
 * ordinary Eloquent call a fixture trait or production service makes
 * (Workspace::create(), a legacy multi-Business insert, the real
 * AgencyBusinessMigrationV1 / NonAgencyBusinessSplitV1 service classes,
 * BusinessLocationRepository, etc.) transparently targets it — exactly as
 * it would against a real pre-Contract-13 production database, and the
 * shared test connection is never touched.
 *
 * CLEANUP GUARANTEE — covers BOTH failure shapes, not just an ordinary
 * test outcome:
 *
 *  A. setUp() itself fails partway (a migrate:fresh/rollback/verification/
 *     config error) AFTER the disposable database already exists. PHPUnit
 *     never calls tearDown() when setUp() throws, so relying on tearDown()
 *     alone would leak the database here. setUp() therefore wraps every
 *     step after database creation in its own try/catch: on failure it
 *     restores config('database.default'), purges connections, drops the
 *     disposable database itself, and only then rethrows the ORIGINAL
 *     exception — a secondary failure while cleaning up is swallowed
 *     rather than allowed to replace it, so the real cause is always what
 *     the test run reports.
 *  B. setUp() completes successfully. Ordinary tearDown() owns cleanup
 *     exactly once, exactly as before, regardless of whether the test
 *     itself passed, failed an assertion, or threw.
 *
 * $historicalDatabaseName/$historicalConnectionName are nullable and start
 * null specifically so cleanup logic — in either path above — can tell
 * whether a disposable database genuinely exists to drop, rather than
 * assuming a typed property was ever initialized. Both are cleared the
 * moment the database is dropped, so the same cleanup method used by
 * setUp()'s catch and by tearDown() can never attempt a double drop.
 *
 * Deliberately NOT RefreshDatabase: RefreshDatabase's own migrate:fresh
 * targets config('database.default') at the moment it first runs in a
 * process, which would reapply every migration (Contract 13's constraint
 * included) against whichever database happens to be default and defeat
 * the entire point of this class.
 */
abstract class PreContract13HistoricalTestCase extends TestCase
{
    use RollsBackContract13Migration;

    private ?string $historicalDatabaseName = null;

    private ?string $historicalConnectionName = null;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Captured before anything else happens, unconditionally — so
        // cleanup can always restore it, whether or not config('database.
        // default') ever actually got switched away from it below.
        $this->originalDefaultConnection = config('database.default');

        [$this->historicalDatabaseName, $this->historicalConnectionName] = TemporaryTestDatabase::beginEnforcementDatabase();

        try {
            $this->afterHistoricalDatabaseCreated();

            Artisan::call('migrate:fresh', ['--database' => $this->historicalConnectionName, '--force' => true]);

            $this->rollbackContract13Migration($this->historicalConnectionName);
            $this->assertBusinessesWorkspaceIdUniqueIsAbsent($this->historicalConnectionName);

            config(['database.default' => $this->historicalConnectionName]);
            DB::purge($this->historicalConnectionName);
        } catch (\Throwable $setupException) {
            $this->cleanupHistoricalDatabase();

            throw $setupException;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupHistoricalDatabase();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Idempotent and safe to call from either setUp()'s catch block or
     * ordinary tearDown() — never both meaningfully for the same test,
     * since PHPUnit skips tearDown() whenever setUp() threw, but written
     * so a future refactor calling it twice would still only drop once.
     * Any exception raised while cleaning up (e.g. the drop itself fails)
     * is swallowed here rather than propagated, so it can never mask a
     * real setup or test failure the caller is already handling — the
     * shared/default test database this disposable one was created
     * alongside is never touched by any of this.
     */
    private function cleanupHistoricalDatabase(): void
    {
        if ($this->originalDefaultConnection !== null) {
            try {
                config(['database.default' => $this->originalDefaultConnection]);
                DB::purge('mysql');
            } catch (\Throwable) {
                // Swallowed — see method docblock.
            }

            $this->originalDefaultConnection = null;
        }

        if ($this->historicalDatabaseName === null || $this->historicalConnectionName === null) {
            return;
        }

        $databaseName = $this->historicalDatabaseName;
        $connectionName = $this->historicalConnectionName;

        // Cleared before the drop itself, not after: if the drop below
        // throws, this method must still never be re-entrant on the same
        // database (no double-drop attempt), and the exception is
        // swallowed immediately below regardless.
        $this->historicalDatabaseName = null;
        $this->historicalConnectionName = null;

        try {
            TemporaryTestDatabase::endEnforcementDatabase($databaseName, $connectionName);
        } catch (\Throwable) {
            // Swallowed — see method docblock.
        }
    }

    /**
     * Test-only extension point, a no-op by default: called immediately
     * after the disposable database exists and is registered, before
     * anything else in setUp() runs. Exists solely so
     * PreContract13HistoricalTestCaseLifecycleTest's dedicated probe
     * subclass can throw here to prove — against this class's own real
     * setUp()/cleanupHistoricalDatabase() flow, never a reimplementation
     * of it — that a setup failure after database creation still drops
     * the database and still reports the original exception. No shipped
     * subclass overrides this.
     */
    protected function afterHistoricalDatabaseCreated(): void
    {
    }

    /**
     * The connection name subclasses can use for explicit
     * DB::connection($this->historicalConnection())->table(...) raw-insert
     * fixtures — most test bodies never need this directly, because
     * config('database.default') already resolves every Eloquent/Schema
     * call there for the duration of the test.
     */
    protected function historicalConnection(): string
    {
        if ($this->historicalConnectionName === null) {
            throw new RuntimeException('historicalConnection() called outside a completed historical setUp().');
        }

        return $this->historicalConnectionName;
    }

    /**
     * Independent proof — a fresh, direct information_schema query, never
     * merely trusting rollbackContract13Migration()'s own migrations-table
     * bookkeeping — that this disposable database genuinely allows more
     * than one Business per Workspace before any fixture is seeded.
     */
    private function assertBusinessesWorkspaceIdUniqueIsAbsent(string $connection): void
    {
        $exists = DB::connection($connection)->table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('INDEX_NAME', 'businesses_workspace_id_unique')
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                'Refusing to run a historical test: businesses_workspace_id_unique is still present after rolling back Contract 13.'
            );
        }
    }
}
