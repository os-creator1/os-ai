<?php

namespace Tests\Feature\Support;

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
        $this->assertSame('.env.testing', $this->app->environmentFile());
        $this->assertSame(
            '.env.testing',
            basename($this->app->environmentFilePath())
        );
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
        $this->assertSame('.env.testing', $this->app->environmentFile());
        $this->assertFileDoesNotExist($temporary, 'Restoring must delete the temporary copy.');
        $this->assertDirectoryDoesNotExist(dirname($temporary));

        // Restoring twice must be a harmless no-op.
        $this->restoreEnvironmentFile();
        $this->assertSame(base_path(), $this->app->environmentPath());

        // Leave this test's own isolation in place for tearDown.
        $this->useTemporaryEnvironmentFile();
    }

    public function test_the_real_dot_env_is_restored_even_when_a_hardcoded_writer_edits_it(): void
    {
        // App\Models\AppConfig::setEnv() hardcodes base_path('.env') and
        // cannot be redirected without changing production code, so the
        // trait snapshots that file instead. This proves the snapshot
        // actually restores it.
        $realEnv = base_path('.env');
        $before = is_file($realEnv) ? File::get($realEnv) : null;

        File::put($realEnv, "AIBOS_DIRECT_WRITE=1\n");
        $this->assertSame("AIBOS_DIRECT_WRITE=1\n", File::get($realEnv));

        $this->restoreEnvironmentFile();

        $this->assertSame(
            $before,
            is_file($realEnv) ? File::get($realEnv) : null,
            'The real .env was not restored byte-for-byte.'
        );

        $this->useTemporaryEnvironmentFile();
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
