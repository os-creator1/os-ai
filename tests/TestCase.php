<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\UsesTemporaryEnvironmentFile;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * NO TEST MAY LEAVE THE DEVELOPER'S ENVIRONMENT FILES EDITED.
     *
     * Several production write paths — every platform-settings save, the
     * branding upload service, the demo-mode toggle — rewrite an
     * environment file wholesale. Reproduced against a pristine checkout
     * of this commit: one `tests/Feature/Settings` run rewrote
     * `.env.testing` (APP_NAME became "Test App", MAIL_DRIVER became
     * "smtp", every blank line was deleted, and the suite's own fixture
     * values were appended, secret-shaped ones included) and also
     * modified `.env`.
     *
     * That is a cross-run and cross-lane isolation break: the next run
     * starts from an environment the previous run edited.
     *
     * Applying the isolation here, on the base class, closes it at the
     * one place that cannot be forgotten — every test in this repository
     * extends this class. Tests that assert on written environment
     * values read them back through readActiveEnvValue(), which
     * addresses the same disposable copy the writers write. The settings
     * suite reaches it through SettingsTestHelpers::readEnvValue(), a
     * thin delegate that exists so this class does not declare the name
     * `readEnvValue` itself — see that method for why.
     *
     * @see \Tests\Support\UsesTemporaryEnvironmentFile
     * @see docs/automation/SETTINGS-ENV-ISOLATION-REMEDIATION.md
     */
    use UsesTemporaryEnvironmentFile;

    /**
     * PHPUnit runs tearDown() after a passing test, after a failed
     * assertion and after an uncaught exception, so cleaning up here
     * covers every outcome a test can have.
     *
     * ORDER MATTERS, AND IT IS THE OPPOSITE OF THE OBVIOUS ONE.
     *
     * parent::tearDown() runs
     * Illuminate\...\InteractsWithTestCaseLifecycle::tearDownTheTestEnvironment(),
     * which calls callBeforeApplicationDestroyedCallbacks() before it
     * flushes and nulls the application. Real work happens in those
     * callbacks: Tests\Feature\Automations\Concerns\UsesFreshSchema
     * registers one that runs `migrate:fresh`, and this repository's
     * migrations write the environment file.
     *
     * An earlier revision restored FIRST and then called
     * parent::tearDown(). That handed the application back to the
     * repository's own `.env.testing` and only then let those callbacks
     * migrate — so the migrations correctly wrote "the active
     * environment file", which by then was the real one. A full-suite run
     * caught it: `.env.testing` came back carrying APP_TIME_FORMAT, the
     * OPENAI_* keys, TERMS_OF_USE and PRIVACY_POLICY.
     *
     * Deferring until after parent::tearDown() keeps the disposable copy
     * in force for the entire teardown, callbacks included. The
     * application is null by then, which is exactly right: there is no
     * longer anything to hand paths back to, restoreEnvironmentFile() is
     * null-safe, and the remaining work is deleting the disposable
     * directory.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreEnvironmentFile();
    }
}
