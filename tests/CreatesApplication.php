<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabaseSafety;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * ENVIRONMENT ISOLATION IS INSTALLED HERE, NOT IN setUp().
     *
     * Laravel's own Illuminate\Foundation\Testing\TestCase::setUp() runs
     * setUpTheTestEnvironment(), which calls refreshApplication() — and
     * therefore this method, including the kernel bootstrap below —
     * before it calls setUpTraits(), where RefreshDatabase performs its
     * migrate:fresh.
     *
     * So anything installed from a subclass's setUp() after
     * parent::setUp() is already too late: LoadEnvironmentVariables,
     * LoadConfiguration and every migration have run by then. Three
     * migrations write the environment file directly, and `migrate:fresh`
     * on its own was measured moving the repository `.env` mtime.
     *
     * Installing between the Application's construction and
     * `$app->make(Kernel::class)->bootstrap()` is the only point that is
     * both late enough to have an Application to configure and early
     * enough that nothing has read the environment yet.
     *
     * @see \Tests\Support\UsesTemporaryEnvironmentFile::installTemporaryEnvironmentFile()
     * @see docs/automation/SETTINGS-ENV-ISOLATION-REMEDIATION.md
     *
     * THE DATABASE-NAME GATE BELOW SITS AT THE SAME SEAM, FOR THE SAME
     * REASON. `Tests\Support\TestDatabaseSafety` was, until now, only
     * consulted by the small set of test files that explicitly call it —
     * mainly the subprocess concurrency runners. An ordinary
     * `RefreshDatabase` test never called it at all, so its `migrate:fresh`
     * ran against whatever `DB_DATABASE` this disposable environment copy
     * resolved to, unchecked. Reproduced directly: pointing this seam at
     * `acme_test_live` — the helper's own documented example of a name
     * that must never be accepted — let a real Usage test run `migrate:fresh`
     * and write rows into it with zero refusal.
     *
     * This is the one place that check can live once for every test built
     * on this trait, rather than being copied into each test file again:
     * config is already loaded by the kernel bootstrap on the line above,
     * so the connection's configured name is known, and `setUpTraits()` —
     * where `RefreshDatabase` performs its `migrate:fresh` — has not run
     * yet. `TestDatabaseSafety::activeTestDatabase()` reads
     * `DB::connection()->getDatabaseName()`, which is a config read, not a
     * live connection, so an unsafe name is refused before anything is
     * opened against it. This is the existing single authority, called
     * exactly as every other caller calls it: no new policy, no relaxed
     * assertion, and production code depends on none of this — only a test
     * bootstrap trait under `tests/` does.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // The Application exists but has bootstrapped nothing: its
        // environment path is still the repository root and no
        // configuration has been read. Point it at a disposable copy
        // first, so every stage below sees only that copy.
        $this->installTemporaryEnvironmentFile($app);

        $app->make(Kernel::class)->bootstrap();

        TestDatabaseSafety::activeTestDatabase();

        return $app;
    }
}
