<?php

namespace Tests\Fixtures;

use App\Models\AppConfig;
use Tests\TestCase;

use function App\Helpers\write_env;

/**
 * A PROBE, not part of any suite.
 *
 * It lives under tests/Fixtures/, which phpunit.xml includes in neither
 * testsuite, so it never runs during a normal suite. It is invoked
 * explicitly, as a subprocess, by
 * Tests\Feature\Support\TemporaryEnvironmentFileTest — to prove that
 * environment-file isolation holds for the outcomes an in-process test
 * cannot produce for itself: a genuine assertion failure and a genuine
 * uncaught exception. Both abort the test method before any cleanup the
 * test itself could perform, so only tearDown() can save them, and only
 * a separate process can observe what was left behind.
 *
 * Each method prints the disposable environment path it was handed and
 * its own process id, so the parent can verify afterwards that the
 * directory was removed whatever the outcome, and that two concurrent
 * children were never handed the same one.
 */
class EnvironmentIsolationProbeTest extends TestCase
{
    public function test_probe_passing(): void
    {
        $this->reportEnvironmentPath('passing');
        $this->writeMarker('passing');

        $this->assertTrue(true);
    }

    public function test_probe_failing_assertion(): void
    {
        $this->reportEnvironmentPath('failing');
        $this->writeMarker('failing');

        $this->assertTrue(false, 'deliberate failure — this probe exists in order to fail');
    }

    public function test_probe_uncaught_exception(): void
    {
        $this->reportEnvironmentPath('throwing');
        $this->writeMarker('throwing');

        throw new \RuntimeException('deliberate exception — this probe exists in order to throw');
    }

    /**
     * Writes through BOTH real production writers, so the probe
     * exercises the exact paths that used to edit the developer's own
     * environment files rather than a stand-in for them.
     *
     * App\Helpers\write_env() has always used
     * app()->environmentFilePath(). App\Models\AppConfig::setEnv() used
     * to hardcode base_path('.env') and now uses the same seam; it is
     * included here precisely so a regression in that fix shows up as a
     * mutated real file, in a separate process, under every test
     * outcome.
     *
     * setEnv() rewrites only lines that already match its key, so the
     * key is seeded through write_env() first.
     */
    private function writeMarker(string $case): void
    {
        write_env('AIBOS_PROBE_MARKER', 'probe-' . $case);

        write_env('AIBOS_PROBE_APPCONFIG', 'seeded');
        AppConfig::setEnv('AIBOS_PROBE_APPCONFIG', 'appconfig-' . $case);
    }

    private function reportEnvironmentPath(string $case): void
    {
        fwrite(STDOUT, sprintf(
            "PROBE %s PID=%d ENVPATH=%s\n",
            $case,
            getmypid(),
            $this->app->environmentFilePath()
        ));
    }
}
