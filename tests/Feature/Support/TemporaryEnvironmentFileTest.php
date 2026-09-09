<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

use function App\Helpers\write_env;

/**
 * Proves the environment-file isolation that Tests\TestCase applies to
 * every test in this repository.
 *
 * WHY THIS MATTERS. Every platform-settings save, the branding upload
 * service and the demo-mode toggle end at App\Helpers\write_env(), which
 * rewrites app()->environmentFilePath() wholesale — `.env.testing` under
 * APP_ENV=testing. Before the isolation was added, running the suite
 * permanently edited the developer's own environment file: it collapsed
 * every line ending and left behind whatever values the last test
 * submitted. APP_NAME="Test App", APP_TIMEZONE="America/New_York" and
 * APP_FOOTER_COMPANY_NAME="Keep Company" were all residue of that, and
 * they broke branding and lease-window assertions in later runs, in this
 * lane and in every other lane sharing the machine.
 *
 * The outcomes an in-process test cannot produce for itself — a real
 * assertion failure and a real uncaught exception — are covered by
 * driving tests/Fixtures/EnvironmentIsolationProbeTest.php as a
 * subprocess and inspecting what it left behind.
 */
class TemporaryEnvironmentFileTest extends TestCase
{
    private const PROBE = 'tests/Fixtures/EnvironmentIsolationProbeTest.php';

    public function test_the_application_is_pointed_at_a_temporary_file_that_exists(): void
    {
        $path = $this->app->environmentFilePath();

        $this->assertFileExists($path);
        $this->assertStringContainsString('aibos-env-', $path, 'The active environment file must be a disposable copy.');
        $this->assertStringNotContainsString(base_path(), $path, 'The disposable copy must live outside the repository.');
    }

    public function test_the_temporary_file_is_seeded_with_the_real_environment(): void
    {
        // Whatever the real file defines must still be readable, or tests
        // that depend on existing keys would silently see an empty
        // environment.
        $this->assertNotNull($this->readEnvValue('APP_KEY'));
        $this->assertSame('mysql', $this->readEnvValue('DB_CONNECTION'));
    }

    public function test_each_call_yields_a_unique_temporary_file_and_removes_the_previous_one(): void
    {
        $first = $this->app->environmentFilePath();

        $second = $this->useTemporaryEnvironmentFile();

        $this->assertNotSame($first, $second, 'Every activation must get its own file.');
        $this->assertFileExists($second);
        $this->assertFileDoesNotExist(
            $first,
            'Re-activating must not leave the previous temporary directory behind.'
        );
    }

    public function test_a_write_lands_in_the_temporary_file_and_never_in_the_real_one(): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');

        $before = [
            'env' => is_file($realEnv) ? md5_file($realEnv) : null,
            'testing' => is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
        ];

        write_env('AIBOS_ISOLATION_MARKER', 'written-by-a-test');

        $this->assertSame('written-by-a-test', $this->readEnvValue('AIBOS_ISOLATION_MARKER'));
        $this->assertStringContainsString(
            'AIBOS_ISOLATION_MARKER',
            (string) File::get($this->app->environmentFilePath())
        );

        $this->assertSame(
            $before['env'],
            is_file($realEnv) ? md5_file($realEnv) : null,
            'A test must never modify the real .env.'
        );
        $this->assertSame(
            $before['testing'],
            is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
            'A test must never modify the real .env.testing.'
        );

        $this->assertStringNotContainsString(
            'AIBOS_ISOLATION_MARKER',
            is_file($realTestingEnv) ? (string) File::get($realTestingEnv) : ''
        );
    }

    public function test_a_value_written_by_one_test_does_not_leak_into_the_next(): void
    {
        // The previous test wrote AIBOS_ISOLATION_MARKER into ITS
        // temporary file. This test has its own, so the key must be gone —
        // this is the cross-test leak that used to happen through the
        // shared real file.
        $this->assertNull(
            $this->readEnvValue('AIBOS_ISOLATION_MARKER'),
            'A value written by an earlier test leaked into this one.'
        );
    }

    public function test_restoring_returns_the_application_to_its_original_environment_file(): void
    {
        $temporary = $this->app->environmentFilePath();

        $this->restoreEnvironmentFile();

        $restored = $this->app->environmentFilePath();

        $this->assertNotSame($temporary, $restored);
        $this->assertSame(base_path(), $this->app->environmentPath());
        $this->assertFileDoesNotExist($temporary, 'Restoring must delete the temporary directory.');

        // Leave this test's own isolation in place for tearDown.
        $this->useTemporaryEnvironmentFile();
    }

    /**
     * The three outcomes, driven as real subprocesses.
     *
     * @dataProvider probeOutcomes
     */
    public function test_isolation_holds_for_every_test_outcome(string $filter, string $marker): void
    {
        $realTestingEnv = base_path('.env.testing');
        $beforeTesting = is_file($realTestingEnv) ? md5_file($realTestingEnv) : null;
        $beforeEnv = md5_file(base_path('.env'));

        $process = $this->runProbe($filter);

        preg_match('/PROBE \S+ ENVPATH=(.+)/', $process->getOutput(), $matches);

        $this->assertNotEmpty(
            $matches,
            "The probe did not report its environment path.\n" . $process->getOutput() . $process->getErrorOutput()
        );

        $childEnvPath = trim($matches[1]);

        $this->assertStringContainsString('aibos-env-', $childEnvPath);
        $this->assertFileDoesNotExist(
            $childEnvPath,
            "The [{$marker}] outcome left its temporary environment file behind."
        );
        $this->assertDirectoryDoesNotExist(
            dirname($childEnvPath),
            "The [{$marker}] outcome left its temporary directory behind."
        );

        $this->assertSame($beforeEnv, md5_file(base_path('.env')), 'The probe modified the real .env.');
        $this->assertSame(
            $beforeTesting,
            is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
            'The probe modified the real .env.testing.'
        );
        $this->assertStringNotContainsString(
            'AIBOS_PROBE_MARKER',
            is_file($realTestingEnv) ? (string) File::get($realTestingEnv) : ''
        );
    }

    public static function probeOutcomes(): array
    {
        return [
            'normal completion' => ['test_probe_passing', 'passing'],
            'assertion failure' => ['test_probe_failing_assertion', 'failing'],
            'uncaught exception' => ['test_probe_uncaught_exception', 'throwing'],
        ];
    }

    public function test_concurrent_processes_never_share_a_temporary_environment_file(): void
    {
        $one = $this->runProbe('test_probe_passing');
        $two = $this->runProbe('test_probe_passing');

        $pathOne = $this->probeEnvPath($one);
        $pathTwo = $this->probeEnvPath($two);

        $this->assertNotSame(
            $pathOne,
            $pathTwo,
            'Two test processes were handed the same temporary environment file.'
        );
        $this->assertNotSame(dirname($pathOne), dirname($pathTwo));
        $this->assertFileDoesNotExist($pathOne);
        $this->assertFileDoesNotExist($pathTwo);
    }

    private function runProbe(string $filter): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$php, 'vendor/bin/phpunit', '--no-output', '--filter', $filter, self::PROBE],
            base_path(),
            // The child must resolve the same disposable database this
            // process is using, never fall back to the canonical one.
            ['DB_DATABASE' => \Illuminate\Support\Facades\DB::connection()->getDatabaseName()]
        );

        $process->setTimeout(180);
        $process->run();

        return $process;
    }

    private function probeEnvPath(Process $process): string
    {
        preg_match('/PROBE \S+ ENVPATH=(.+)/', $process->getOutput(), $matches);

        $this->assertNotEmpty(
            $matches,
            "The probe did not report its environment path.\n" . $process->getOutput() . $process->getErrorOutput()
        );

        return trim($matches[1]);
    }
}
