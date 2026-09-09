<?php

namespace Tests\Fixtures;

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
     * Writes through the real production writer, so the probe exercises
     * the exact path that used to edit the developer's own environment
     * file rather than a stand-in for it.
     */
    private function writeMarker(string $case): void
    {
        write_env('AIBOS_PROBE_MARKER', 'probe-' . $case);
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
