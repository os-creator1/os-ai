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
 * CLEANUP GUARANTEE. This does not use a try/finally, unlike the
 * callback-based WorkspaceBusinessOneToOneEnforcementTest precedent —
 * PHPUnit's own contract supplies the same guarantee here: tearDown() runs
 * after every test method that reaches it, whether that method passes,
 * fails an assertion, or throws, as long as setUp() itself returned
 * without throwing. setUp() here performs only disposable-database
 * create/migrate/rollback — no test logic — so that guarantee holds for
 * every subclass test unconditionally.
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

    private string $historicalDatabaseName;

    private string $historicalConnectionName;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->historicalDatabaseName, $this->historicalConnectionName] = TemporaryTestDatabase::beginEnforcementDatabase();

        Artisan::call('migrate:fresh', ['--database' => $this->historicalConnectionName, '--force' => true]);

        $this->rollbackContract13Migration($this->historicalConnectionName);
        $this->assertBusinessesWorkspaceIdUniqueIsAbsent($this->historicalConnectionName);

        $this->originalDefaultConnection = config('database.default');
        config(['database.default' => $this->historicalConnectionName]);
        DB::purge($this->historicalConnectionName);
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->originalDefaultConnection]);
        DB::purge('mysql');

        TemporaryTestDatabase::endEnforcementDatabase($this->historicalDatabaseName, $this->historicalConnectionName);

        parent::tearDown();
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
