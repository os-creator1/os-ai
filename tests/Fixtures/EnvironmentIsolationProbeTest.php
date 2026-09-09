<?php

namespace Tests\Fixtures;

use Tests\TestCase;

/**
 * A PROBE, not part of any suite.
 *
 * It lives under tests/Fixtures/, which phpunit.xml does not include in
 * either testsuite, so it never runs during a normal suite. It is invoked
 * explicitly by TemporaryEnvironmentFileTest as a subprocess, to prove
 * that environment-file isolation holds under outcomes an in-process test
 * cannot produce for itself: a genuine assertion failure and a genuine
 * uncaught exception.
 *
 * Each method prints the temporary environment path it was given, so the
 * parent can verify the directory was removed after the child exited,
 * whatever the outcome.
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
        $this->assertTrue(false, 'deliberate failure — the probe exists to fail');
    }

    public function test_probe_uncaught_exception(): void
    {
        $this->reportEnvironmentPath('throwing');
        $this->writeMarker('throwing');

        throw new \RuntimeException('deliberate exception — the probe exists to throw');
    }

    /**
     * Writes into the temporary file through the real production writer,
     * so the probe exercises the path that used to edit the developer's
     * own .env.testing.
     */
    private function writeMarker(string $case): void
    {
        \App\Helpers\write_env('AIBOS_PROBE_MARKER', 'probe-' . $case);
    }

    private function reportEnvironmentPath(string $case): void
    {
        fwrite(STDOUT, sprintf(
            "PROBE %s ENVPATH=%s\n",
            $case,
            $this->app->environmentFilePath()
        ));
    }
}
