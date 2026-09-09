<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * The guard must validate the database LARAVEL ACTUALLY RESOLVES, not the
 * name a caller asked for.
 *
 * Between the two sit `.env`, `.env.<APP_ENV>`, a stale
 * bootstrap/cache/config.php and `DATABASE_URL` — any of which can point
 * the connection somewhere the caller never named. Since the next step is
 * always destructive (migrations), the check runs in a process booted
 * under exactly the environment those migrations will use:
 * scripts/resolve-test-database.php.
 *
 * Exit codes it must produce:
 *   0  a permitted disposable test database
 *   3  anything else, or nothing
 *   4  resolved something other than what the caller selected
 */
class ResolvedTestDatabaseGuardTest extends TestCase
{
    private const RESOLVER = 'scripts/resolve-test-database.php';

    private const UNSAFE_EXIT = 3;

    private const MISMATCH_EXIT = 4;

    /**
     * @dataProvider refusedDatabaseNames
     */
    public function test_an_unsafe_resolved_database_is_refused(string $label, string $name): void
    {
        $process = $this->resolve(['DB_DATABASE' => $name]);

        $this->assertSame(
            self::UNSAFE_EXIT,
            $process->getExitCode(),
            "[{$label}] must be refused with exit " . self::UNSAFE_EXIT . ".\n" . $process->getErrorOutput()
        );
        $this->assertStringContainsString('Refusing to run', $process->getErrorOutput());
        $this->assertStringNotContainsString('RESOLVED_DATABASE=', $process->getOutput());
    }

    public static function refusedDatabaseNames(): array
    {
        return [
            'empty' => ['empty', ''],
            'production-looking' => ['production-looking', 'ultimatesms_production'],
            'bare application database' => ['bare application database', 'ultimatesms'],
            'uppercase' => ['uppercase', 'ultimatesms_testing_ABC'],
            'quote' => ['quote', "ultimatesms_testing_a'b"],
            'wildcard' => ['wildcard', 'ultimatesms_testing_%'],
            'path separator' => ['path separator', 'ultimatesms_testing_a/b'],
            'connection url' => ['connection url', 'mysql://root@host/db'],
            'semicolon' => ['semicolon', 'ultimatesms_testing_a;DROP'],
            'excessive length' => ['excessive length', 'ultimatesms_testing_' . str_repeat('a', 70)],
        ];
    }

    public function test_a_database_url_override_is_refused_because_it_hides_the_real_target(): void
    {
        $process = $this->resolve([
            'DB_DATABASE' => TestDatabaseSafety::CANONICAL,
            'DATABASE_URL' => 'mysql://root@127.0.0.1/ultimatesms',
        ]);

        $this->assertSame(self::UNSAFE_EXIT, $process->getExitCode());
        $this->assertStringContainsString('DATABASE_URL is set', $process->getErrorOutput());
    }

    public function test_a_stale_config_cache_that_disagrees_with_the_selection_is_refused(): void
    {
        $active = DB::connection()->getDatabaseName();
        $other = $active === TestDatabaseSafety::CANONICAL
            ? TestDatabaseSafety::CANONICAL . '_guardprobe'
            : TestDatabaseSafety::CANONICAL;

        $cachePath = base_path('bootstrap/cache/config.php');
        $hadCache = is_file($cachePath);
        $previous = $hadCache ? (string) File::get($cachePath) : null;

        try {
            // Cache the configuration while it points at $other, then ask
            // for $active. Laravel loads the cache and ignores the
            // environment, so the two disagree — exactly the situation a
            // CLI-argument-only check cannot see.
            $this->cacheConfigPointingAt($other);

            $process = $this->resolve([
                'DB_DATABASE' => $active,
                'EXPECTED_TEST_DATABASE' => $active,
            ]);

            $this->assertSame(
                self::MISMATCH_EXIT,
                $process->getExitCode(),
                "A cached configuration disagreeing with the selection must be refused.\n" . $process->getErrorOutput()
            );
            $this->assertStringContainsString($active, $process->getErrorOutput());
            $this->assertStringContainsString($other, $process->getErrorOutput());
        } finally {
            if ($previous !== null) {
                File::put($cachePath, $previous);
            } elseif (is_file($cachePath)) {
                File::delete($cachePath);
            }
        }
    }

    public function test_the_canonical_database_and_an_isolated_sibling_are_both_accepted(): void
    {
        foreach ([TestDatabaseSafety::CANONICAL, DB::connection()->getDatabaseName()] as $name) {
            $process = $this->resolve(['DB_DATABASE' => $name, 'EXPECTED_TEST_DATABASE' => $name]);

            $this->assertSame(
                0,
                $process->getExitCode(),
                "[{$name}] must be accepted.\n" . $process->getErrorOutput()
            );
            $this->assertStringContainsString("RESOLVED_DATABASE={$name}", $process->getOutput());
        }
    }

    public function test_a_subprocess_cannot_substitute_a_different_database(): void
    {
        $active = DB::connection()->getDatabaseName();

        // The child is told to use one database while the parent's
        // expectation names another — the shape of a subprocess trying to
        // fall back to the canonical name.
        $process = $this->resolve([
            'DB_DATABASE' => TestDatabaseSafety::CANONICAL,
            'EXPECTED_TEST_DATABASE' => $active === TestDatabaseSafety::CANONICAL
                ? TestDatabaseSafety::CANONICAL . '_guardprobe'
                : $active,
        ]);

        $this->assertSame(self::MISMATCH_EXIT, $process->getExitCode());
        $this->assertStringContainsString('Refusing to run', $process->getErrorOutput());
    }

    /**
     * @param  array<string, string>  $env
     */
    private function resolve(array $env): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$php, self::RESOLVER],
            base_path(),
            array_merge(['APP_ENV' => 'testing'], $env)
        );

        $process->setTimeout(180);
        $process->run();

        return $process;
    }

    private function cacheConfigPointingAt(string $database): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$php, 'artisan', 'config:cache'],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => $database]
        );

        $process->setTimeout(180);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'Could not cache the configuration for the probe: ' . $process->getErrorOutput()
        );
    }
}
