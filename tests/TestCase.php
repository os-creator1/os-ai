<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\UsesTemporaryEnvironmentFile;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * NO TEST MAY EVER WRITE THE DEVELOPER'S ENVIRONMENT FILE.
     *
     * Several production write paths (every platform-settings save, the
     * branding upload service, the demo-mode toggle) end at
     * App\Helpers\write_env(), which rewrites
     * `app()->environmentFilePath()` wholesale. Under APP_ENV=testing
     * that is `.env.testing`, so running the suite used to permanently
     * edit the developer's own environment file: it collapsed the file's
     * line endings and left behind whatever values the last test
     * submitted. Verified on this branch — one baseline run changed
     * NOCAPTCHA_SITEKEY and FACEBOOK_CLIENT_ID and rewrote every line
     * ending.
     *
     * That made every later run, in every lane sharing the machine,
     * start from an environment a previous run had edited. Values like
     * APP_NAME="Test App" and APP_TIMEZONE="America/New_York" — the two
     * that broke the branding and heartbeat assertions — are exactly the
     * residue this leaves behind.
     *
     * Redirecting the environment path for every test closes it at the
     * one place that cannot be forgotten. Tests that assert on written
     * env values read them back through readEnvValue(), which addresses
     * the same disposable copy.
     */
    use UsesTemporaryEnvironmentFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryEnvironmentFile();
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironmentFile();

        parent::tearDown();
    }
}
