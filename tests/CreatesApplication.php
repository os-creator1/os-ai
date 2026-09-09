<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

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

        return $app;
    }
}
