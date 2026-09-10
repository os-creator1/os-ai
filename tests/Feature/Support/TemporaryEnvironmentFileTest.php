<?php

namespace Tests\Feature\Support;

use App\Models\AppConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

use function App\Helpers\write_env;

/**
 * Proves the environment-file isolation Tests\TestCase applies to every
 * test in this repository.
 *
 * WHY THIS MATTERS. Every platform-settings save, the branding upload
 * service and the demo-mode toggle rewrite an environment file
 * wholesale. Against a pristine checkout of this commit, one
 * `tests/Feature/Settings` run rewrote `.env.testing` — APP_NAME became
 * "Test App", MAIL_DRIVER became "smtp", every blank line was deleted,
 * and the suite's own fixture values were appended, secret-shaped ones
 * included — and separately modified `.env` through
 * AppConfig::setEnv()'s hardcoded `base_path('.env')`.
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
        $this->assertStringContainsString(
            'aibos-env-',
            $path,
            'The active environment file must be a disposable copy.'
        );
        $this->assertStringNotContainsString(
            base_path(),
            $path,
            'The disposable copy must live outside the repository.'
        );
    }

    public function test_the_temporary_file_keeps_the_original_environment_filename(): void
    {
        // The application's own notion of which environment file is in
        // force must be unchanged, or a restore could hand back the
        // wrong filename.
        //
        // WHICH filename that is depends on the checkout, and must not
        // be hardcoded. `.env.testing` is matched by `.gitignore`'s
        // `.env.*`, so it is absent on a clean checkout, and Laravel then
        // correctly selects `.env` even with APP_ENV=testing supplied by
        // phpunit.xml. An earlier revision asserted `.env.testing`
        // literally and would have failed there — on correct behaviour.
        $selected = self::expectedSelectedEnvironmentFile();

        $this->assertSame($selected, $this->app->environmentFile());
        $this->assertSame($selected, basename($this->app->environmentFilePath()));
        $this->assertSame($selected, basename((string) $this->realEnvironmentFilePath()));
    }

    public function test_the_temporary_file_is_seeded_from_the_active_environment_file(): void
    {
        // Whatever the real file defines must still be readable, or
        // tests depending on existing keys would silently see an empty
        // environment.
        $this->assertNotNull($this->readActiveEnvValue('APP_KEY'));
        $this->assertSame('mysql', $this->readActiveEnvValue('DB_CONNECTION'));
    }

    public function test_each_activation_yields_a_unique_file_and_deletes_the_previous_one(): void
    {
        $first = $this->app->environmentFilePath();

        $second = $this->useTemporaryEnvironmentFile();

        $this->assertNotSame($first, $second, 'Every activation must get its own file.');
        $this->assertFileExists($second);
        $this->assertFileDoesNotExist(
            $first,
            'Re-activating must delete the previous temporary copy.'
        );
        $this->assertDirectoryDoesNotExist(
            dirname($first),
            'Re-activating must delete the previous temporary directory.'
        );
    }

    public function test_a_write_lands_in_the_temporary_file_and_never_in_the_real_ones(): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');

        $before = [
            'env' => is_file($realEnv) ? md5_file($realEnv) : null,
            'testing' => is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
        ];

        write_env('AIBOS_ISOLATION_MARKER', 'written-by-a-test');

        $this->assertSame('written-by-a-test', $this->readActiveEnvValue('AIBOS_ISOLATION_MARKER'));
        $this->assertStringContainsString(
            'AIBOS_ISOLATION_MARKER',
            (string) File::get($this->app->environmentFilePath())
        );

        clearstatcache();

        $this->assertSame(
            $before['env'],
            is_file($realEnv) ? md5_file($realEnv) : null,
            'A test must never leave the real .env modified.'
        );
        $this->assertSame(
            $before['testing'],
            is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
            'A test must never leave the real .env.testing modified.'
        );
        $this->assertStringNotContainsString(
            'AIBOS_ISOLATION_MARKER',
            is_file($realTestingEnv) ? (string) File::get($realTestingEnv) : ''
        );
    }

    public function test_a_value_written_before_re_activation_does_not_survive_it(): void
    {
        // The deterministic form of the cross-test leak: re-activating is
        // exactly what the next test's setUp() does, and it must seed
        // from the real file rather than from the copy already in force.
        write_env('AIBOS_LEAK_MARKER', 'must-not-survive');

        $this->assertSame('must-not-survive', $this->readActiveEnvValue('AIBOS_LEAK_MARKER'));

        $this->useTemporaryEnvironmentFile();

        $this->assertNull(
            $this->readActiveEnvValue('AIBOS_LEAK_MARKER'),
            'A value written before re-activation leaked into the new environment file.'
        );
    }

    public function test_restoring_returns_the_application_to_its_original_environment_file(): void
    {
        $temporary = $this->app->environmentFilePath();

        $this->restoreEnvironmentFile();

        $this->assertNotSame($temporary, $this->app->environmentFilePath());
        $this->assertSame(base_path(), $this->app->environmentPath());
        $this->assertSame(self::expectedSelectedEnvironmentFile(), $this->app->environmentFile());
        $this->assertFileDoesNotExist($temporary, 'Restoring must delete the temporary copy.');
        $this->assertDirectoryDoesNotExist(dirname($temporary));

        // Restoring twice must be a harmless no-op.
        $this->restoreEnvironmentFile();
        $this->assertSame(base_path(), $this->app->environmentPath());

        // Leave this test's own isolation in place for tearDown.
        $this->useTemporaryEnvironmentFile();
    }

    /**
     * AppConfig::setEnv() used to hardcode base_path('.env'). An earlier
     * revision of the harness contained that by snapshotting and
     * restoring the real file, which is NOT isolation — see the trait's
     * docblock. The writer now uses app()->environmentFilePath(), and
     * these assertions run BEFORE any teardown, so they prove the real
     * file is never touched in the first place rather than proving it was
     * repaired afterwards.
     */
    public function test_app_config_set_env_writes_the_disposable_file_and_never_the_real_one(): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');

        clearstatcache();
        $before = [
            'envBytes' => is_file($realEnv) ? File::get($realEnv) : null,
            'envMtime' => is_file($realEnv) ? filemtime($realEnv) : null,
            'testingBytes' => is_file($realTestingEnv) ? File::get($realTestingEnv) : null,
            'testingMtime' => is_file($realTestingEnv) ? filemtime($realTestingEnv) : null,
        ];

        // A key that already exists in the seeded copy, because
        // setEnv()'s substring find/replace only rewrites matching lines.
        write_env('AIBOS_APPCONFIG_TARGET', 'seeded');
        AppConfig::setEnv('AIBOS_APPCONFIG_TARGET', 'written-by-appconfig');

        // 1. The disposable file changed.
        $this->assertSame('written-by-appconfig', $this->readActiveEnvValue('AIBOS_APPCONFIG_TARGET'));
        $this->assertStringContainsString(
            'written-by-appconfig',
            (string) File::get($this->app->environmentFilePath())
        );

        clearstatcache();

        // 2. The real .env bytes are unchanged.
        $this->assertSame(
            $before['envBytes'],
            is_file($realEnv) ? File::get($realEnv) : null,
            'AppConfig::setEnv() modified the real .env.'
        );

        // 3. The real .env mtime is unchanged — proves it was not even
        //    opened for writing, which a bytes-only check cannot show.
        $this->assertSame(
            $before['envMtime'],
            is_file($realEnv) ? filemtime($realEnv) : null,
            'The real .env was opened for writing (its mtime moved).'
        );

        // 4. The same for the real .env.testing.
        $this->assertSame(
            $before['testingBytes'],
            is_file($realTestingEnv) ? File::get($realTestingEnv) : null,
            'AppConfig::setEnv() modified the real .env.testing.'
        );
        $this->assertSame(
            $before['testingMtime'],
            is_file($realTestingEnv) ? filemtime($realTestingEnv) : null,
            'The real .env.testing was opened for writing (its mtime moved).'
        );

        // 5. Neither real file can be read to discover the test value.
        $this->assertStringNotContainsString(
            'written-by-appconfig',
            is_file($realEnv) ? (string) File::get($realEnv) : ''
        );
        $this->assertStringNotContainsString(
            'written-by-appconfig',
            is_file($realTestingEnv) ? (string) File::get($realTestingEnv) : ''
        );
    }

    public function test_app_config_set_env_does_not_create_a_real_dot_env_that_did_not_exist(): void
    {
        $realEnv = base_path('.env');

        if (is_file($realEnv)) {
            // The file exists in this checkout, so the "must not create
            // it" property is asserted the only honest way available:
            // prove the writer resolves somewhere else entirely.
            $this->assertNotSame(
                realpath($realEnv),
                realpath($this->app->environmentFilePath()),
                'The active environment file resolves to the real .env.'
            );
            $this->assertStringNotContainsString(
                base_path(),
                $this->app->environmentFilePath(),
                'The active environment file is inside the repository.'
            );

            return;
        }

        AppConfig::setEnv('AIBOS_SHOULD_NOT_CREATE', 'x');

        clearstatcache();
        $this->assertFileDoesNotExist(
            $realEnv,
            'AppConfig::setEnv() created a real .env that did not exist before.'
        );
    }

    public function test_another_process_cannot_observe_a_test_value_through_the_real_files(): void
    {
        // A second PHP process, reading the real files directly the way
        // any other tool on the machine would, must see nothing this test
        // wrote. This is the property snapshot-and-restore could never
        // provide, because the value is present in the real file for the
        // whole window before teardown.
        write_env('AIBOS_CROSS_PROCESS', 'leaked-value');
        AppConfig::setEnv('AIBOS_CROSS_PROCESS', 'leaked-value-appconfig');

        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $script = <<<'PHP'
$paths = [$argv[1], $argv[2]];
$seen = '';
foreach ($paths as $path) {
    if (is_file($path)) {
        $seen .= file_get_contents($path);
    }
}
echo str_contains($seen, 'AIBOS_CROSS_PROCESS') ? 'LEAKED' : 'CLEAN';
PHP;

        $process = new Process([$php, '-r', $script, base_path('.env'), base_path('.env.testing')]);
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(
            'CLEAN',
            trim($process->getOutput()),
            'Another process read this test\'s value out of a real environment file.'
        );
    }

    /**
     * The decoder is the exact inverse of
     * App\Helpers\format_dotenv_value(), so the round trip is the
     * assertion: write through the real writer, read back through the
     * real reader, expect the original value.
     */
    #[DataProvider('roundTripValues')]
    public function test_a_written_value_round_trips_exactly(string $label, string $value): void
    {
        write_env('AIBOS_ROUNDTRIP', $value);

        $this->assertSame(
            $value,
            $this->readActiveEnvValue('AIBOS_ROUNDTRIP'),
            "The [{$label}] value did not survive a write/read round trip."
        );
    }

    public static function roundTripValues(): array
    {
        return [
            'plain' => ['plain', 'AI Business OS'],
            'empty' => ['empty', ''],
            'double quote' => ['double quote', 'say "hello" now'],
            'single backslash' => ['single backslash', 'C:\\Users\\dev'],
            // The case a str_replace-based decoder gets wrong: the
            // encoded form is C:\\new, whose second backslash and the
            // following "n" look exactly like an escaped newline.
            'backslash before n' => ['backslash before n', 'C:\\new'],
            'trailing backslash' => ['trailing backslash', 'ends with\\'],
            'escaped quote sequence' => ['escaped quote sequence', 'a\\"b'],
            'real newline' => ['real newline', "line one\nline two"],
            'hash and dollar' => ['hash and dollar', 'a #comment $VAR'],
            'equals sign' => ['equals sign', 'key=value=more'],
        ];
    }

    public function test_a_carriage_return_inside_a_value_is_normalised_to_a_newline_by_the_writer(): void
    {
        // Documented, deliberate LOSS in the production writer, not a
        // decoder defect: App\Helpers\format_dotenv_value() maps "\r\n",
        // "\n" and "\r" all onto the single escape \n, so a value
        // containing CRLF comes back containing LF. Asserting the exact
        // normalisation keeps the real contract visible; asserting a
        // faithful CRLF round trip would fail, and "fixing" it would
        // mean editing production code this branch must not touch.
        write_env('AIBOS_CRLF_VALUE', "line one\r\nline two");

        $this->assertSame("line one\nline two", $this->readActiveEnvValue('AIBOS_CRLF_VALUE'));

        write_env('AIBOS_CR_VALUE', "line one\rline two");

        $this->assertSame("line one\nline two", $this->readActiveEnvValue('AIBOS_CR_VALUE'));
    }

    public function test_an_absent_key_and_an_explicitly_empty_key_are_distinguishable(): void
    {
        $this->assertNull(
            $this->readActiveEnvValue('AIBOS_DEFINITELY_ABSENT'),
            'An absent key must read as null.'
        );

        write_env('AIBOS_EXPLICITLY_EMPTY', '');

        $this->assertSame(
            '',
            $this->readActiveEnvValue('AIBOS_EXPLICITLY_EMPTY'),
            'A key explicitly cleared to an empty value must read as an empty string, not null.'
        );
    }

    public function test_a_crlf_environment_file_decodes_without_a_trailing_carriage_return(): void
    {
        // The original defect: trim($value, "\"\n") cannot strip \r, so
        // the closing quote stayed attached and the value came back as
        // 'AI Business OS"'.
        File::put(
            $this->app->environmentFilePath(),
            "APP_NAME=\"AI Business OS\"\r\nSECOND_KEY=\"second\"\r\n"
        );

        $this->assertSame('AI Business OS', $this->readActiveEnvValue('APP_NAME'));
        $this->assertSame('second', $this->readActiveEnvValue('SECOND_KEY'));
    }

    public function test_an_unquoted_value_is_read_verbatim(): void
    {
        File::put(
            $this->app->environmentFilePath(),
            "UNQUOTED=localhost\nUNQUOTED_EMPTY=\n"
        );

        $this->assertSame('localhost', $this->readActiveEnvValue('UNQUOTED'));
        $this->assertSame('', $this->readActiveEnvValue('UNQUOTED_EMPTY'));
    }

    public function test_a_key_that_is_a_prefix_of_another_is_not_confused_with_it(): void
    {
        File::put(
            $this->app->environmentFilePath(),
            "APP_NAME=\"short\"\nAPP_NAME_EXTRA=\"long\"\n"
        );

        $this->assertSame('short', $this->readActiveEnvValue('APP_NAME'));
        $this->assertSame('long', $this->readActiveEnvValue('APP_NAME_EXTRA'));
    }

    /**
     * The three outcomes, driven as real subprocesses.
     */
    #[DataProvider('probeOutcomes')]
    public function test_isolation_holds_for_every_test_outcome(string $filter, string $outcome): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');
        $beforeEnv = is_file($realEnv) ? md5_file($realEnv) : null;
        $beforeTesting = is_file($realTestingEnv) ? md5_file($realTestingEnv) : null;

        $process = $this->runProbe($filter);
        $childEnvPath = $this->probeEnvPath($process);

        $this->assertStringContainsString('aibos-env-', $childEnvPath);
        $this->assertFileDoesNotExist(
            $childEnvPath,
            "The [{$outcome}] outcome left its temporary environment file behind."
        );
        $this->assertDirectoryDoesNotExist(
            dirname($childEnvPath),
            "The [{$outcome}] outcome left its temporary directory behind."
        );

        clearstatcache();

        $this->assertSame(
            $beforeEnv,
            is_file($realEnv) ? md5_file($realEnv) : null,
            "The [{$outcome}] outcome modified the real .env."
        );
        $this->assertSame(
            $beforeTesting,
            is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
            "The [{$outcome}] outcome modified the real .env.testing."
        );
        foreach (['AIBOS_PROBE_MARKER', 'AIBOS_PROBE_APPCONFIG', 'appconfig-' . $outcome] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                is_file($realTestingEnv) ? (string) File::get($realTestingEnv) : '',
                "The [{$outcome}] outcome leaked [{$needle}] into the real .env.testing."
            );
            $this->assertStringNotContainsString(
                $needle,
                is_file($realEnv) ? (string) File::get($realEnv) : '',
                "The [{$outcome}] outcome leaked [{$needle}] into the real .env."
            );
        }
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
        // Genuinely concurrent: both children are started before either
        // is waited on, so their lifetimes overlap. Sequential runs would
        // pass trivially and prove nothing about collision.
        $one = $this->startProbe('test_probe_passing');
        $two = $this->startProbe('test_probe_passing');

        $one->wait();
        $two->wait();

        $pathOne = $this->probeEnvPath($one);
        $pathTwo = $this->probeEnvPath($two);

        $this->assertNotSame(
            $pathOne,
            $pathTwo,
            'Two concurrent test processes were handed the same temporary environment file.'
        );
        $this->assertNotSame(
            dirname($pathOne),
            dirname($pathTwo),
            'Two concurrent test processes shared a temporary environment directory.'
        );
        $this->assertNotSame(
            $this->probePid($one),
            $this->probePid($two),
            'The two probes did not run as separate processes.'
        );

        $this->assertFileDoesNotExist($pathOne);
        $this->assertFileDoesNotExist($pathTwo);
        $this->assertDirectoryDoesNotExist(dirname($pathOne));
        $this->assertDirectoryDoesNotExist(dirname($pathTwo));
    }

    /**
     * The concurrency case the review named: two processes both driving
     * AppConfig::setEnv(), overlapping in time, against a shared real
     * file that neither may touch.
     */
    public function test_two_concurrent_app_config_writers_never_collide(): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');

        clearstatcache();
        $before = [
            'envBytes' => is_file($realEnv) ? md5_file($realEnv) : null,
            'envMtime' => is_file($realEnv) ? filemtime($realEnv) : null,
            'testingBytes' => is_file($realTestingEnv) ? md5_file($realTestingEnv) : null,
            'testingMtime' => is_file($realTestingEnv) ? filemtime($realTestingEnv) : null,
        ];

        $one = $this->startProbe('test_probe_passing');
        $two = $this->startProbe('test_probe_passing');

        $one->wait();
        $two->wait();

        $pathOne = $this->probeEnvPath($one);
        $pathTwo = $this->probeEnvPath($two);

        // Different disposable environment paths.
        $this->assertNotSame($pathOne, $pathTwo);
        $this->assertNotSame(dirname($pathOne), dirname($pathTwo));
        $this->assertNotSame($this->probePid($one), $this->probePid($two));

        // No cross-process value leakage: neither child's value is
        // visible anywhere but its own (already-deleted) copy.
        clearstatcache();
        foreach ([$realEnv, $realTestingEnv] as $path) {
            $contents = is_file($path) ? (string) File::get($path) : '';
            $this->assertStringNotContainsString('AIBOS_PROBE_APPCONFIG', $contents);
            $this->assertStringNotContainsString('appconfig-passing', $contents);
        }

        // No real-file mutation, by bytes and by mtime.
        $this->assertSame($before['envBytes'], is_file($realEnv) ? md5_file($realEnv) : null);
        $this->assertSame($before['envMtime'], is_file($realEnv) ? filemtime($realEnv) : null);
        $this->assertSame($before['testingBytes'], is_file($realTestingEnv) ? md5_file($realTestingEnv) : null);
        $this->assertSame($before['testingMtime'], is_file($realTestingEnv) ? filemtime($realTestingEnv) : null);

        // Complete temporary-directory cleanup.
        $this->assertFileDoesNotExist($pathOne);
        $this->assertFileDoesNotExist($pathTwo);
        $this->assertDirectoryDoesNotExist(dirname($pathOne));
        $this->assertDirectoryDoesNotExist(dirname($pathTwo));
    }

    /**
     * Forced termination, killed AFTER the writers have run.
     *
     * An earlier revision killed the child as soon as it saw `ENVPATH=`,
     * with a comment claiming that meant the child "has booted,
     * activated isolation and run both writers". That was wrong: the
     * probe prints its path BEFORE writing, so the kill routinely landed
     * before either production writer had touched anything. The test
     * proved that booting is safe, not that a kill mid-write is — which
     * is the interesting case, because that is when a writer could have
     * a repository file open.
     *
     * The probe now emits `PROBE-WROTE` only after both writers have run
     * AND their values have been read back out of the disposable copy,
     * then blocks. This test waits for that signal, verifies the child
     * genuinely reached both writers, and only then kills it.
     */
    public function test_a_forcibly_terminated_process_leaves_the_real_files_untouched(): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');

        $before = $this->realFileFingerprints();

        $process = $this->startProbe('test_probe_blocks_until_killed');

        // 1. Wait for the POST-WRITE signal, not the path line.
        $deadline = microtime(true) + 180;
        $seen = '';

        while (microtime(true) < $deadline) {
            $seen .= $process->getIncrementalOutput();

            if (str_contains($seen, 'PROBE-WROTE') || str_contains($seen, 'PROBE-WRITE-FAILED')) {
                break;
            }

            if (! $process->isRunning()) {
                break;
            }

            usleep(50_000);
        }

        $seen .= $process->getIncrementalOutput();

        $this->assertStringNotContainsString(
            'PROBE-WRITE-FAILED',
            $seen,
            "The probe could not confirm its own writes:\n" . $seen . $process->getErrorOutput()
        );

        // 2. Verify the child reached BOTH writers before it was killed.
        preg_match(
            '/PROBE-WROTE blocking PID=(\d+) MARKER=(\S+) APPCONFIG=(\S+) ENVPATH=(.+)/',
            $seen,
            $wrote
        );

        $this->assertNotEmpty(
            $wrote,
            "The probe never signalled that it had written:\nSTDOUT:\n" . $seen
            . "\nSTDERR:\n" . $process->getErrorOutput()
        );

        $this->assertSame(
            'probe-blocking',
            $wrote[2],
            'The probe did not reach App\Helpers\write_env().'
        );
        $this->assertSame(
            'appconfig-blocking',
            $wrote[3],
            'The probe did not reach App\Models\AppConfig::setEnv().'
        );

        $childEnvPath = trim($wrote[4]);
        $this->assertStringContainsString('aibos-env-', $childEnvPath);
        $this->assertTrue($process->isRunning(), 'The probe should still be blocked, waiting to be killed.');

        // 3. SIGKILL-equivalent: no signal handler, no shutdown function,
        //    no tearDown.
        $process->stop(0, 9);

        // 4. Repository files byte- and mtime-identical.
        $this->assertSame(
            $before,
            $this->realFileFingerprints(),
            'A forcibly terminated test modified or re-opened a repository environment file.'
        );

        // 5. No unsafe repository write: neither value the child wrote is
        //    observable in either repository file.
        foreach ([$realEnv, $realTestingEnv] as $path) {
            $contents = is_file($path) ? (string) File::get($path) : '';

            foreach (['AIBOS_PROBE_MARKER', 'AIBOS_PROBE_APPCONFIG', 'probe-blocking', 'appconfig-blocking'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "A forcibly terminated test leaked [{$needle}] into " . basename($path) . '.'
                );
            }
        }

        // 6. Clean ONLY the child's own disposable directory. It is
        //    outside the repository, so it could never have contaminated
        //    the working tree; removing it just leaves the machine as it
        //    was found. A killed process runs no shutdown sweep of its
        //    own, which is why this is the parent's job.
        $childDirectory = dirname($childEnvPath);

        $this->assertStringStartsWith(
            sys_get_temp_dir(),
            $childDirectory,
            'Refusing to delete a directory outside the system temp directory.'
        );

        if (is_dir($childDirectory)) {
            File::deleteDirectory($childDirectory);
        }

        $this->assertDirectoryDoesNotExist($childDirectory);
    }

    // --- Pre-bootstrap isolation ---

    /**
     * The whole point of moving activation into CreatesApplication: the
     * configuration the kernel bootstrapped must have come from the
     * disposable copy, not from the repository file.
     */
    public function test_kernel_bootstrap_loaded_configuration_from_the_disposable_copy(): void
    {
        $active = $this->app->environmentFilePath();

        $this->assertStringContainsString('aibos-env-', $active);
        $this->assertStringNotContainsString(base_path(), $active);

        // Prove it by content, not only by path: write a distinctive
        // value into the copy, re-bootstrap configuration, and read it
        // back through config(). A repository file was never touched.
        write_env('AIBOS_BOOTSTRAP_PROBE', 'from-the-disposable-copy');

        $this->app->make(\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class)->bootstrap($this->app);

        $this->assertSame('from-the-disposable-copy', env('AIBOS_BOOTSTRAP_PROBE'));
        $this->assertSame(
            'from-the-disposable-copy',
            $this->readActiveEnvValue('AIBOS_BOOTSTRAP_PROBE')
        );
    }

    public function test_the_disposable_copy_carries_the_bytes_laravel_would_have_loaded(): void
    {
        $real = $this->realEnvironmentFilePath();

        $this->assertNotNull($real);
        $this->assertSame(
            base_path(self::expectedSelectedEnvironmentFile()),
            $real,
            'The framework selection was not reproduced.'
        );
        $this->assertFileExists($real);

        // The copy this test started from is seeded from that file. Its
        // own writes have not happened yet at the point of comparison, so
        // re-install to get a pristine copy and compare bytes.
        $fresh = $this->useTemporaryEnvironmentFile();

        $this->assertSame(
            file_get_contents($real),
            file_get_contents($fresh),
            'The disposable copy does not carry the source bytes Laravel would have loaded.'
        );
        $this->assertSame(basename($real), basename($fresh));
    }

    /**
     * Which environment file this checkout actually presents to Laravel.
     *
     * `.gitignore` matches `.env.*`, so `.env.testing` is untracked and a
     * clean checkout has none. `LoadEnvironmentVariables` only switches
     * to `<base>.<APP_ENV>` when that file exists, so with APP_ENV=testing
     * supplied by phpunit.xml the selection is `.env.testing` on a
     * workstation that has one and `.env` on a checkout that does not.
     * Both are correct, and no test may require the untracked file.
     */
    private static function expectedSelectedEnvironmentFile(): string
    {
        $environment = (string) \Illuminate\Support\Env::get('APP_ENV');

        if ($environment !== '' && is_file(base_path('.env.' . $environment))) {
            return '.env.' . $environment;
        }

        return '.env';
    }

    /**
     * A checkout with no `.env.testing` — the clean-checkout and CI
     * shape — must select `.env`, and one that has it must select it.
     *
     * Both cases are exercised against scratch directories rather than
     * by deleting a repository file, so this test never needs, creates
     * or removes an untracked workstation file. It drives the real
     * installer, so it proves the selection behaviour rather than
     * re-implementing it.
     */
    #[DataProvider('checkoutShapes')]
    public function test_the_installer_selects_the_file_laravel_would_have_selected(
        string $shape,
        bool $withTestingFile,
        string $expectedFile,
    ): void {
        $before = $this->realFileFingerprints();

        $scratch = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'aibos-checkout-' . getmypid() . '-' . bin2hex(random_bytes(6));

        mkdir($scratch, 0777, true);
        file_put_contents(
            $scratch . DIRECTORY_SEPARATOR . '.env',
            "APP_NAME=\"Base Env\"\nCHECKOUT_SHAPE=\"{$shape}\"\n"
        );

        if ($withTestingFile) {
            file_put_contents(
                $scratch . DIRECTORY_SEPARATOR . '.env.testing',
                "APP_NAME=\"Testing Env\"\nCHECKOUT_SHAPE=\"{$shape}\"\n"
            );
        }

        try {
            // Present the scratch directory to the installer exactly as
            // an un-booted application presents the repository root.
            $this->app->useEnvironmentPath($scratch);
            $this->app->loadEnvironmentFrom('.env');

            $copy = $this->installTemporaryEnvironmentFile($this->app);

            $this->assertSame($expectedFile, basename($copy), "[{$shape}] selected the wrong file.");
            $this->assertSame($expectedFile, $this->app->environmentFile());
            $this->assertSame($shape, $this->readActiveEnvValue('CHECKOUT_SHAPE'));
            $this->assertSame(
                $withTestingFile ? 'Testing Env' : 'Base Env',
                $this->readActiveEnvValue('APP_NAME'),
                "[{$shape}] copied the wrong source file's bytes."
            );

            // The copy is disposable and outside the source directory.
            $this->assertStringNotContainsString($scratch, $copy);

            // A production writer lands in the copy, and the scratch
            // source stays byte-identical — the same property the
            // repository files get.
            $sourcePath = $scratch . DIRECTORY_SEPARATOR . $expectedFile;
            $sourceBytes = file_get_contents($sourcePath);
            $sourceMtime = filemtime($sourcePath);

            write_env('CHECKOUT_WRITE_PROBE', 'written');
            AppConfig::setEnv('CHECKOUT_SHAPE', 'overwritten');

            $this->assertSame('written', $this->readActiveEnvValue('CHECKOUT_WRITE_PROBE'));
            $this->assertSame('overwritten', $this->readActiveEnvValue('CHECKOUT_SHAPE'));

            clearstatcache();
            $this->assertSame($sourceBytes, file_get_contents($sourcePath), "[{$shape}] modified its source file.");
            $this->assertSame($sourceMtime, filemtime($sourcePath), "[{$shape}] re-opened its source file for writing.");
        } finally {
            // Hand the application back to the repository, then restore
            // this test's own isolation for tearDown.
            $this->restoreEnvironmentFile();
            $this->app->useEnvironmentPath(base_path());
            $this->app->loadEnvironmentFrom('.env');
            $this->useTemporaryEnvironmentFile();

            self::removeScratchDirectory($scratch);
        }

        // Neither repository file was involved at any point.
        $this->assertSame(
            $before,
            $this->realFileFingerprints(),
            "[{$shape}] modified or re-opened a repository environment file."
        );
    }

    public static function checkoutShapes(): array
    {
        return [
            'clean checkout, no .env.testing' => ['clean-checkout', false, '.env'],
            'workstation with .env.testing' => ['workstation', true, '.env.testing'],
        ];
    }

    private static function removeScratchDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($directory . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($directory);
    }

    /**
     * The blocker this round exists to close. A RefreshDatabase test runs
     * migrate:fresh inside the test process, and three migrations write
     * the environment file. Driving that preparation directly and
     * checking bytes AND mtime proves the repository files are not the
     * ones being written.
     */
    public function test_refresh_database_migration_preparation_does_not_touch_the_repository_files(): void
    {
        $realEnv = base_path('.env');
        $realTestingEnv = base_path('.env.testing');

        clearstatcache();
        $before = $this->realFileFingerprints();
        $beforeContents = [
            $realEnv => is_file($realEnv) ? File::get($realEnv) : null,
            $realTestingEnv => is_file($realTestingEnv) ? File::get($realTestingEnv) : null,
        ];

        $this->artisan('migrate:fresh', ['--force' => true])->run();

        clearstatcache();

        $this->assertSame(
            $before,
            $this->realFileFingerprints(),
            'migrate:fresh modified or re-opened a repository environment file.'
        );

        // The migrations' own keys landed in the disposable copy instead.
        $this->assertNotNull(
            $this->readActiveEnvValue('APP_TIME_FORMAT'),
            'The time-format migration did not write the active environment file.'
        );

        // The destination really is a different file.
        $active = $this->app->environmentFilePath();
        $this->assertNotSame(realpath($realEnv) ?: $realEnv, $active);
        $this->assertNotSame(realpath($realTestingEnv) ?: $realTestingEnv, $active);
        $this->assertStringNotContainsString(base_path(), $active);

        // Byte-for-byte, spelled out rather than only hashed.
        //
        // NOT asserted: that the repository files lack keys such as
        // APP_TIME_FORMAT. They legitimately may contain them — running
        // `php artisan migrate` outside a test is supposed to write the
        // active environment file, which in a normal CLI invocation IS
        // the repository one. An earlier revision asserted their absence
        // and failed on a developer machine where migrate had ever been
        // run normally, which is correct behaviour rather than a defect.
        // What must hold is that THIS run changed nothing.
        foreach ($beforeContents as $path => $contents) {
            $this->assertSame(
                $contents,
                is_file($path) ? File::get($path) : null,
                'migrate:fresh changed the contents of ' . basename($path) . '.'
            );
        }

        $this->assertFileExists($realEnv);
    }

    public function test_tool_version_seeder_addresses_the_disposable_file(): void
    {
        $before = $this->realFileFingerprints();

        // Seed the keys the 3.4.0 branch rewrites so the effect is
        // observable, then run exactly that branch.
        write_env('TRAI_DLT', 'seeded');

        \App\Library\Tool::versionSeeder('3.4.0');

        clearstatcache();

        $this->assertSame(
            $before,
            $this->realFileFingerprints(),
            'Tool::versionSeeder() touched a repository environment file.'
        );
        $this->assertStringContainsString(
            'TRAI_DLT',
            (string) File::get($this->app->environmentFilePath()),
            'Tool::versionSeeder() did not write the active environment file.'
        );
    }

    public function test_pusher_settings_addresses_the_disposable_file(): void
    {
        $before = $this->realFileFingerprints();

        app(\App\Repositories\Contracts\SettingsRepository::class)->pusherSettings([
            'app_id' => 'probe-id',
            'app_key' => 'probe-key',
            'app_secret' => 'probe-secret',
            'app_cluster' => 'probe-cluster',
            'broadcast_driver' => 'log',
        ]);

        clearstatcache();

        $this->assertSame(
            $before,
            $this->realFileFingerprints(),
            'pusherSettings() touched a repository environment file.'
        );
        $this->assertStringContainsString(
            'PUSHER_APP_ID=probe-id',
            (string) File::get($this->app->environmentFilePath()),
            'pusherSettings() did not write the active environment file.'
        );
    }

    /**
     * The teardown-ordering regression, pinned.
     *
     * Laravel runs beforeApplicationDestroyed callbacks inside
     * parent::tearDown(), and UsesFreshSchema registers one that runs
     * migrate:fresh. If this class restored the environment path before
     * calling parent::tearDown(), those migrations would run against the
     * repository file — which is exactly what a full-suite run caught.
     *
     * Registering a callback here and asserting from inside it proves the
     * disposable copy is still in force at that moment.
     */
    public function test_before_application_destroyed_callbacks_still_see_the_disposable_copy(): void
    {
        $realTestingEnv = base_path('.env.testing');
        $before = is_file($realTestingEnv)
            ? [md5_file($realTestingEnv), filemtime($realTestingEnv)]
            : null;

        $observed = null;

        $this->beforeApplicationDestroyed(function () use (&$observed): void {
            $observed = $this->app->environmentFilePath();

            // Do what UsesFreshSchema does at this point: migrate. The
            // migrations write the active environment file, so this is
            // the exact operation that used to escape.
            $this->artisan('migrate:fresh', ['--force' => true])->run();
        });

        // The assertion cannot live inside the callback — a failure there
        // would not be attributed to this test — so it is recorded and
        // checked by a second callback registered afterwards, which
        // Laravel runs in registration order.
        $this->beforeApplicationDestroyed(function () use (&$observed, $before, $realTestingEnv): void {
            if ($observed === null || ! str_contains($observed, 'aibos-env-')) {
                throw new \RuntimeException(
                    'A beforeApplicationDestroyed callback saw [' . var_export($observed, true)
                    . '] instead of a disposable copy: teardown restored too early.'
                );
            }

            clearstatcache();

            $after = is_file($realTestingEnv)
                ? [md5_file($realTestingEnv), filemtime($realTestingEnv)]
                : null;

            if ($after !== $before) {
                throw new \RuntimeException(
                    'A migration run during teardown modified the repository .env.testing.'
                );
            }
        });

        $this->assertTrue(true, 'The real assertions run in the callbacks registered above.');
    }

    public function test_recreating_the_application_does_not_reuse_or_leak_a_previous_copy(): void
    {
        $first = $this->app->environmentFilePath();

        $this->refreshApplication();

        $second = $this->app->environmentFilePath();

        $this->assertNotSame($first, $second, 'A recreated application reused the previous copy.');
        $this->assertFileExists($second);
        $this->assertFileDoesNotExist($first, 'A recreated application leaked the previous copy.');
        $this->assertDirectoryDoesNotExist(dirname($first));
    }

    // --- Mechanical source guarantees ---

    public function test_no_snapshot_and_restore_fallback_exists_in_the_helper(): void
    {
        $source = (string) File::get(base_path('tests/Support/UsesTemporaryEnvironmentFile.php'));

        foreach (self::executableLines($source) as $number => $line) {
            $this->assertStringNotContainsString(
                'base_path(',
                $line,
                "tests/Support/UsesTemporaryEnvironmentFile.php line {$number} addresses a repository path."
            );
        }

        $this->assertStringNotContainsString('realDotEnvSnapshot', $source);
        $this->assertStringNotContainsString('restoreRealDotEnvFile', $source);
        $this->assertStringNotContainsString('guardRealDotEnvFile', $source);
    }

    public function test_no_executable_hardcoded_environment_writer_remains(): void
    {
        $roots = [base_path('app'), base_path('database'), base_path('tests')];
        $allowedReaders = [
            // This file reads the repository files on purpose, to assert
            // they were not written. It never writes one.
            'tests/Feature/Support/TemporaryEnvironmentFileTest.php',
        ];

        $offenders = [];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if (in_array($relative, $allowedReaders, true)) {
                    continue;
                }

                foreach (self::executableLines((string) File::get($file->getPathname())) as $number => $line) {
                    if (preg_match('/base_path\(\s*[\'"]\.env/', $line) === 1) {
                        $offenders[] = "{$relative}:{$number}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Hardcoded environment-file paths remain in executable code:\n" . implode("\n", $offenders)
        );
    }

    /**
     * Strips comments and docblocks so a source assertion cannot be
     * satisfied — or tripped — by prose. Uses PHP's own tokenizer rather
     * than a regular expression, so a `//` inside a string literal is
     * not mistaken for a comment and vice versa.
     *
     * @return array<int, string> line number => executable text
     */
    private static function executableLines(string $source): array
    {
        $lines = [];

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $lines[$line] = ($lines[$line] ?? '') . $text;
        }

        return $lines;
    }

    /**
     * Bytes and mtime for both repository environment files, plus
     * whether each exists — the fingerprint every writer assertion
     * compares. mtime is included deliberately: a writer that rewrites
     * identical bytes still moves it, and that is exactly the signal the
     * migration defect produced.
     *
     * @return array<string, string|int|null|bool>
     */
    private function realFileFingerprints(): array
    {
        clearstatcache();

        $fingerprint = [];

        foreach (['.env', '.env.testing'] as $name) {
            $path = base_path($name);
            $exists = is_file($path);

            $fingerprint[$name . ':exists'] = $exists;
            $fingerprint[$name . ':bytes'] = $exists ? md5_file($path) : null;
            $fingerprint[$name . ':mtime'] = $exists ? filemtime($path) : null;
        }

        return $fingerprint;
    }

    // --- Cleanup boundaries and the bounded rmdir retry ---

    /**
     * Normal teardown removes the file AND the directory.
     *
     * Asserted here from inside the test by driving one full
     * activate/restore cycle, so the property is proven rather than
     * inferred from the absence of leftovers at the end of a run.
     */
    public function test_normal_cleanup_removes_both_the_file_and_the_directory(): void
    {
        $copy = $this->useTemporaryEnvironmentFile();
        $directory = dirname($copy);

        $this->assertFileExists($copy);
        $this->assertDirectoryExists($directory);

        $this->restoreEnvironmentFile();

        clearstatcache();
        $this->assertFileDoesNotExist($copy, 'Cleanup left the environment file behind.');
        $this->assertDirectoryDoesNotExist($directory, 'Cleanup left an empty directory behind.');

        // Leave this test's own isolation in place for tearDown.
        $this->useTemporaryEnvironmentFile();
    }

    /**
     * Re-activation removes the previous file and the previous
     * directory, not merely the file.
     */
    public function test_reactivation_removes_the_previous_file_and_directory(): void
    {
        $first = $this->app->environmentFilePath();
        $firstDirectory = dirname($first);

        $second = $this->useTemporaryEnvironmentFile();

        clearstatcache();
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($first);
        $this->assertDirectoryDoesNotExist($firstDirectory, 'Re-activation left the previous directory behind.');
        $this->assertDirectoryExists(dirname($second));
    }

    /**
     * The bounded retry must not have widened what may be deleted.
     *
     * Two boundaries are asserted directly: a directory belonging to
     * another process id is never touched, and nothing outside the
     * system temp directory is ever a candidate. The sweep is
     * pid-scoped by construction; this proves it.
     */
    public function test_cleanup_never_touches_another_process_directory(): void
    {
        // A decoy shaped exactly like a sibling process's directory,
        // with a pid that is not ours.
        $foreignPid = getmypid() + 1;
        $decoy = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'aibos-env-' . $foreignPid . '-decoy-' . bin2hex(random_bytes(6));

        mkdir($decoy, 0777, true);
        file_put_contents($decoy . DIRECTORY_SEPARATOR . '.env.testing', "DECOY=1\n");

        try {
            // A full activate/restore cycle, which runs the same cleanup
            // the sweep uses.
            $copy = $this->useTemporaryEnvironmentFile();
            $this->restoreEnvironmentFile();

            clearstatcache();
            $this->assertDirectoryDoesNotExist(dirname($copy), 'Our own directory should be gone.');
            $this->assertDirectoryExists($decoy, "Cleanup removed another process's directory.");
            $this->assertFileExists($decoy . DIRECTORY_SEPARATOR . '.env.testing');
        } finally {
            @unlink($decoy . DIRECTORY_SEPARATOR . '.env.testing');
            @rmdir($decoy);
            $this->useTemporaryEnvironmentFile();
        }
    }

    /**
     * Every directory this trait creates is inside the system temp
     * directory and carries this process's pid — the two facts the
     * pid-scoped sweep depends on for safety.
     */
    public function test_every_disposable_directory_is_pid_scoped_and_inside_the_system_temp_directory(): void
    {
        $temp = rtrim(sys_get_temp_dir(), '\\/');

        foreach (range(1, 3) as $ignored) {
            $copy = $this->useTemporaryEnvironmentFile();
            $directory = dirname($copy);

            $this->assertStringStartsWith($temp, $directory);
            $this->assertStringNotContainsString(base_path(), $directory);
            $this->assertStringContainsString('aibos-env-' . getmypid() . '-', basename($directory));
        }
    }

    /**
     * Repeated activation leaves nothing belonging to THIS process.
     *
     * Scoped to this pid on purpose: a concurrent lane's directories are
     * none of this test's business, and asserting on them would make the
     * suite fail for reasons outside its control.
     */
    public function test_repeated_activation_leaves_no_directory_belonging_to_this_process(): void
    {
        foreach (range(1, 12) as $ignored) {
            $copy = $this->useTemporaryEnvironmentFile();
            $this->assertFileExists($copy);
        }

        $current = rtrim(dirname($this->app->environmentFilePath()), '\\/');

        clearstatcache();
        $leftovers = array_values(array_filter(
            glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aibos-env-' . getmypid() . '-*') ?: [],
            static fn (string $path): bool => rtrim($path, '\\/') !== $current
        ));

        $this->assertSame(
            [],
            $leftovers,
            "Repeated activation left directories behind:\n" . implode("\n", $leftovers)
        );
    }

    public function test_no_temporary_environment_directory_outlives_the_suite(): void
    {
        // Every directory this trait creates carries this process's own
        // pid, so a leftover from THIS process is a cleanup failure.
        // Directories belonging to other live processes are none of this
        // test's business and are deliberately not inspected.
        $mine = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aibos-env-' . getmypid() . '-*') ?: [];

        // The one currently in force is this very test's own.
        $current = dirname($this->app->environmentFilePath());

        $leftovers = array_values(array_filter(
            $mine,
            static fn (string $path): bool => rtrim($path, '\\/') !== rtrim($current, '\\/')
        ));

        $this->assertSame(
            [],
            $leftovers,
            "Temporary environment directories from this process were left behind:\n" . implode("\n", $leftovers)
        );
    }

    private function startProbe(string $filter): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$php, 'vendor/bin/phpunit', '--filter', $filter, self::PROBE],
            base_path(),
            // The child must resolve the very same disposable database
            // this process is using, never fall back to the canonical
            // one. Mirrors Tests\Support\TestDatabaseSafety's own
            // parent/child contract.
            ['DB_DATABASE' => DB::connection()->getDatabaseName()]
        );

        $process->setTimeout(300);
        $process->start();

        return $process;
    }

    private function runProbe(string $filter): Process
    {
        $process = $this->startProbe($filter);
        $process->wait();

        return $process;
    }

    private function probeEnvPath(Process $process): string
    {
        return $this->probeField($process, 'ENVPATH');
    }

    private function probePid(Process $process): string
    {
        return $this->probeField($process, 'PID');
    }

    private function probeField(Process $process, string $field): string
    {
        $output = $process->getOutput();

        preg_match('/PROBE \S+ PID=(\d+) ENVPATH=(.+)/', $output, $matches);

        $this->assertNotEmpty(
            $matches,
            "The probe did not report its environment path.\n"
            . "STDOUT:\n" . $output . "\nSTDERR:\n" . $process->getErrorOutput()
        );

        return trim($field === 'PID' ? $matches[1] : $matches[2]);
    }
}
