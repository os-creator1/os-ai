<?php

namespace Tests\Feature\Workspace;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Support\PreContract13OrdinaryCleanupFailureProbe;
use Tests\Feature\Workspace\Support\PreContract13SetupFailureProbe;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Review correction (R5, round 2) — proves PreContract13HistoricalTestCase's
 * cleanup guarantee holds for all three shapes a historical test's
 * lifecycle can end in: (A) a setup failure occurring AFTER the disposable
 * database already exists, where a cleanup failure must be SUPPRESSED so
 * the original setup exception is what gets reported; (B) an ordinary
 * pass/fail/throw once setUp() has completed, where a cleanup failure must
 * instead SURFACE and fail the test rather than be silently swallowed into
 * a false green; and (C) the unchanged pre-existing callback-based
 * withEnforcementDatabase() primitive. A test cannot observe its own
 * class's setUp()/tearDown() outcome from within its own test method (in
 * (A) the method body never runs at all; in (B) the method body itself
 * genuinely passes, and only tearDown() afterward fails), so (A) and (B)
 * are each proven by spawning a dedicated probe as a genuinely separate
 * PHPUnit child process and inspecting PHPUnit's own reported outcome —
 * never a reimplementation of the lifecycle under test.
 */
class PreContract13HistoricalTestCaseLifecycleTest extends TestCase
{
    /**
     * 1. An ordinary historical test's real setUp()/tearDown() pair — the
     * ACTUAL PreContract13HistoricalTestCase lifecycle, not a stand-in —
     * leaves no disposable database behind.
     */
    public function test_a_normal_historical_setup_and_teardown_leaves_no_disposable_database(): void
    {
        $before = $this->enforcementDatabaseCount();

        $process = $this->runPhpUnit([
            '--filter',
            'test_an_agency_with_one_business_needs_no_migration',
            'tests/Feature/Workspace/AgencyBusinessMigrationV1Test.php',
        ]);

        $this->assertTrue(
            $process->isSuccessful(),
            "The normal historical test must pass cleanly:\n" . $process->getOutput() . $process->getErrorOutput()
        );
        $this->assertSame($before, $this->enforcementDatabaseCount(), 'A normal run must leave no disposable database behind.');
    }

    /**
     * 2 & 3. A deliberate exception thrown AFTER the disposable database
     * already exists, but before setUp() otherwise completes, must still
     * drop that database — and the ORIGINAL exception, never a secondary
     * cleanup failure, must be what PHPUnit reports.
     */
    public function test_a_deliberate_setup_failure_after_database_creation_still_drops_it_and_reports_the_original_exception(): void
    {
        $before = $this->enforcementDatabaseCount();

        $process = $this->runPhpUnit(['tests/Feature/Workspace/Support/PreContract13SetupFailureProbe.php']);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertFalse($process->isSuccessful(), "The probe must be reported as an error, never a pass:\n{$output}");
        $this->assertStringContainsString(
            PreContract13SetupFailureProbe::FAILURE_MARKER,
            $output,
            "The ORIGINAL setup exception must be what PHPUnit reports:\n{$output}"
        );
        $this->assertStringNotContainsString(
            PreContract13SetupFailureProbe::UNREACHABLE_BODY_MARKER,
            $output,
            "The test body must never execute — setUp() must throw before it:\n{$output}"
        );

        $this->assertSame(
            $before,
            $this->enforcementDatabaseCount(),
            'A setup failure occurring after database creation must still drop the disposable database.'
        );
    }

    /**
     * 3 & 4. An ordinary cleanup failure — setUp() having already
     * completed successfully, so there is no earlier exception to protect
     * — must surface and fail the test, never be silently swallowed into
     * a false green; and parent::tearDown() must still have run (proven
     * indirectly: the probe's own disposable database is genuinely gone
     * afterward, which only parent::tearDown() completing without being
     * skipped, plus the probe's own real drop-then-throw override,
     * guarantees).
     */
    public function test_an_ordinary_cleanup_failure_surfaces_and_fails_the_test_while_parent_teardown_still_runs(): void
    {
        $before = $this->enforcementDatabaseCount();

        $process = $this->runPhpUnit(['tests/Feature/Workspace/Support/PreContract13OrdinaryCleanupFailureProbe.php']);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertFalse(
            $process->isSuccessful(),
            "A cleanup failure during ordinary tearDown() must fail the test, never pass silently:\n{$output}"
        );
        $this->assertStringContainsString(
            PreContract13OrdinaryCleanupFailureProbe::CLEANUP_FAILURE_MARKER,
            $output,
            "The cleanup failure itself must be what PHPUnit reports:\n{$output}"
        );

        // The probe's dropHistoricalDatabase() override performs the REAL
        // drop before throwing its synthetic failure, so nothing was
        // actually leaked by exercising this path — the count is
        // unaffected by the failure it deliberately injects afterward.
        $this->assertSame(
            $before,
            $this->enforcementDatabaseCount(),
            "The probe's own real drop must still have happened despite the synthetic failure layered on top of it."
        );
    }

    /**
     * 4 (continued). Contract 13's constraint on the SHARED database —
     * never touched by any of the above — remains present throughout.
     */
    public function test_the_shared_database_still_enforces_the_contract_13_unique_constraint_afterward(): void
    {
        $exists = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('INDEX_NAME', 'businesses_workspace_id_unique')
            ->exists();

        $this->assertTrue($exists, "Contract 13's constraint must remain present and untouched on the shared database.");
    }

    /**
     * 5. The pre-existing, callback-based TemporaryTestDatabase::
     * withEnforcementDatabase() — WorkspaceBusinessOneToOneEnforcementTest's
     * own precedent, and the primitive beginEnforcementDatabase()/
     * endEnforcementDatabase() were split out of — is unchanged.
     */
    public function test_the_callback_based_with_enforcement_database_is_unchanged(): void
    {
        $before = $this->enforcementDatabaseCount();

        $seenDatabaseName = TemporaryTestDatabase::withEnforcementDatabase(
            fn (string $databaseName, string $connectionName) => $databaseName
        );

        $this->assertNotSame('', $seenDatabaseName);
        $this->assertSame($before, $this->enforcementDatabaseCount(), 'withEnforcementDatabase() must still leave no disposable database.');
    }

    private function runPhpUnit(array $arguments): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$php, 'vendor/bin/phpunit', ...$arguments],
            base_path(),
            // The child must resolve the very same database this process
            // is using, never fall back to the canonical one — mirrors
            // Tests\Support\TestDatabaseSafety's own parent/child contract
            // (the probe/normal test's own setUp() derives its disposable
            // database FROM this base, so it must be the one this process
            // is actually connected to).
            ['DB_DATABASE' => DB::connection()->getDatabaseName()]
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    /**
     * Every disposable database this lane's own base test database could
     * currently own, counted directly from information_schema — never a
     * guess at a specific generated name (its pid/random suffix is not
     * predictable from here), so a before/after comparison around some
     * exercise is what proves nothing was left behind by it.
     */
    private function enforcementDatabaseCount(): int
    {
        $base = TestDatabaseSafety::activeTestDatabase();
        $escapedBase = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $base);

        return DB::table('information_schema.SCHEMATA')
            ->where('SCHEMA_NAME', 'like', $escapedBase . '\_enforcement\_%')
            ->count();
    }
}
