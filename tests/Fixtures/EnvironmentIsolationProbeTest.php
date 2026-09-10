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
     * The forced-termination probe.
     *
     * The parent used to kill the child as soon as it saw `ENVPATH=`,
     * and a comment claimed that meant "it has booted, activated
     * isolation and run both writers". That was false: the path line is
     * printed BEFORE writeMarker() runs, so the kill could — and often
     * did — land before either production writer had touched anything.
     * The test therefore proved only that booting is safe, not that a
     * kill *mid-write* is.
     *
     * This method emits a second, explicit signal that is printed only
     * after BOTH writers have run AND their values have been read back
     * out of the disposable copy. Reaching that line is proof the child
     * did the work; the parent waits for it before killing.
     *
     * It then blocks. The block is bounded rather than infinite so a
     * parent that dies without killing this child cannot leave it
     * running forever; the parent kills it long before the ceiling.
     */
    public function test_probe_blocks_until_killed(): void
    {
        $this->reportEnvironmentPath('blocking');
        $this->writeMarker('blocking');

        // Read back through the same disposable copy the writers wrote,
        // so the signal cannot be emitted unless both writes actually
        // landed where they were supposed to.
        $marker = $this->readActiveEnvValue('AIBOS_PROBE_MARKER');
        $appConfig = $this->readActiveEnvValue('AIBOS_PROBE_APPCONFIG');

        if ($marker !== 'probe-blocking' || $appConfig !== 'appconfig-blocking') {
            fwrite(STDOUT, sprintf(
                "PROBE-WRITE-FAILED MARKER=%s APPCONFIG=%s\n",
                var_export($marker, true),
                var_export($appConfig, true)
            ));
            fflush(STDOUT);

            $this->fail('The probe could not confirm its own writes; it will not signal readiness.');
        }

        fwrite(STDOUT, sprintf(
            "PROBE-WROTE blocking PID=%d MARKER=%s APPCONFIG=%s ENVPATH=%s\n",
            getmypid(),
            $marker,
            $appConfig,
            $this->app->environmentFilePath()
        ));
        fflush(STDOUT);

        // Block until the parent kills this process. A killed process
        // runs no tearDown and no shutdown function, which is the whole
        // point: whatever it leaves behind must already be harmless.
        $ceiling = microtime(true) + 300;

        while (microtime(true) < $ceiling) {
            usleep(50_000);
        }

        $this->fail('The probe was never terminated; the parent failed to kill it.');
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

    /**
     * Printed BEFORE the writers run. It reports where the disposable
     * copy is, nothing more — a parent that needs to know the writers
     * have finished must wait for the PROBE-WROTE line instead.
     */
    private function reportEnvironmentPath(string $case): void
    {
        fwrite(STDOUT, sprintf(
            "PROBE %s PID=%d ENVPATH=%s\n",
            $case,
            getmypid(),
            $this->app->environmentFilePath()
        ));
        fflush(STDOUT);
    }
}
