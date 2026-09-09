<?php

/**
 * The supported way to run this repository's test suite.
 *
 *   composer test:baseline
 *   composer test:baseline -- --database=ultimatesms_testing_lane_c
 *   composer test:baseline -- tests/Feature/Workspace
 *
 * WHAT IT GUARANTEES
 *
 *   1. The target database is a DISPOSABLE test database, validated by the
 *      one shared rule (Tests\Support\TestDatabaseSafety). A production-like,
 *      empty or unsafe name aborts before anything runs.
 *   2. Stale application caches are cleared, so a run never depends on a
 *      config/route cache another checkout produced.
 *   3. Migrations are applied to that database, and only that database.
 *   4. The compiled assets the suite needs actually exist — in particular
 *      the Mix manifest entries, which resources/views/panels/scripts.blade.php
 *      throws on when missing while APP_DEBUG is true.
 *   5. The suite runs with deterministic environment settings, so results
 *      do not depend on one developer's .env.
 *   6. Raw logs are preserved under storage/logs/test-baseline/.
 *   7. The exit code is TRUTHFUL: non-zero whenever any step failed, and
 *      non-zero when the suite discovered zero tests.
 *
 * WHAT IT NEVER DOES
 *
 *   * It never writes .env or .env.testing.
 *   * It never touches a database that is not a validated disposable test
 *     database.
 *   * It never contacts a non-local host without DB_HOST being set to one
 *     deliberately by the caller.
 *
 * PLATFORM. Written in PHP precisely so Windows and Linux share ONE
 * implementation with no divergent shell wrappers to keep in sync.
 */

require __DIR__ . '/../vendor/autoload.php';

use Tests\Support\TestDatabaseSafety;

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_UNSAFE_DATABASE = 3;
const EXIT_STEP_FAILED = 4;
const EXIT_NO_TESTS = 5;

$root = dirname(__DIR__);
chdir($root);

// ---------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------

$argvRest = array_slice($argv, 1);
$database = getenv('DB_DATABASE') ?: TestDatabaseSafety::CANONICAL;
$skipAssets = false;
$phpunitArgs = [];

foreach ($argvRest as $argument) {
    if (str_starts_with($argument, '--database=')) {
        $database = substr($argument, strlen('--database='));

        continue;
    }

    if ($argument === '--skip-asset-check') {
        $skipAssets = true;

        continue;
    }

    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, <<<'TEXT'
        Usage: php scripts/test-baseline.php [options] [phpunit arguments...]

          --database=<name>    Disposable test database to use. Defaults to
                               $DB_DATABASE, else ultimatesms_testing.
                               Permitted: ultimatesms_testing and
                               ultimatesms_testing_<safe suffix>.
          --skip-asset-check   Do not fail when compiled assets are missing.
          -h, --help           Show this help.

        Any remaining arguments are passed straight through to PHPUnit, so a
        focused run works exactly as it does normally:

          php scripts/test-baseline.php tests/Feature/Workspace
          php scripts/test-baseline.php --filter=WorkspaceManagerTest

        TEXT);

        exit(EXIT_OK);
    }

    $phpunitArgs[] = $argument;
}

// ---------------------------------------------------------------------
// 1. Validate the target database BEFORE anything else runs
// ---------------------------------------------------------------------

try {
    TestDatabaseSafety::assertSafeTestDatabaseName($database);
} catch (RuntimeException $e) {
    fwrite(STDERR, "[test-baseline] {$e->getMessage()}\n");
    exit(EXIT_UNSAFE_DATABASE);
}

$logDirectory = $root . '/storage/logs/test-baseline';

if (! is_dir($logDirectory) && ! mkdir($logDirectory, 0775, true) && ! is_dir($logDirectory)) {
    fwrite(STDERR, "[test-baseline] Could not create the log directory {$logDirectory}.\n");
    exit(EXIT_STEP_FAILED);
}

$runId = date('Ymd-His') . '-' . $database;

/**
 * Deterministic environment for every child process.
 *
 * APP_ENV/APP_DEBUG/APP_NAME/APP_TIMEZONE are pinned to the values
 * config/app.php itself declares as defaults, so a run cannot inherit an
 * arbitrary local value. DB_DATABASE is the validated name above and
 * nothing else.
 */
$childEnv = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_NAME' => 'AI Business OS',
    'APP_TIMEZONE' => 'UTC',
    'DB_DATABASE' => $database,
    'EXPECTED_TEST_DATABASE' => $database,
];

/**
 * Run one step, stream it, and keep the raw log.
 *
 * Child output goes to a FILE, never to a pipe.
 *
 * On Windows, PHP's proc_open() pipe streams are always blocking —
 * stream_set_blocking($pipe, false) silently does nothing — so the usual
 * "read stdout, then read stderr, repeat" loop deadlocks the moment the
 * child fills the pipe this process is not currently reading. `artisan
 * migrate` reproduces it immediately: the child parks with zero CPU and a
 * sleeping database connection, and the runner waits forever.
 *
 * File descriptors have no such failure mode on either platform, and the
 * log this step must keep anyway is written directly by the child instead
 * of being reassembled here.
 *
 * @param  array<int, string>  $command
 * @return array{code: int, output: string}
 */
$runStep = function (string $name, array $command) use ($childEnv, $logDirectory, $runId): array {
    fwrite(STDOUT, "\n[test-baseline] {$name}\n");

    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
    $logPath = "{$logDirectory}/{$runId}-{$slug}.log";

    // Truncated up front so a re-run never appends to a previous one.
    file_put_contents($logPath, '');

    $environment = array_merge(getenv(), $childEnv);
    $descriptors = [
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];

    $process = proc_open($command, $descriptors, $pipes, null, $environment);

    if (! is_resource($process)) {
        fwrite(STDERR, '[test-baseline] Could not start: ' . implode(' ', $command) . "\n");

        return ['code' => EXIT_STEP_FAILED, 'output' => ''];
    }

    // Tail the log so the caller sees progress live rather than a long
    // silence followed by a wall of text.
    $streamed = 0;

    while (true) {
        clearstatcache(true, $logPath);
        $size = (int) @filesize($logPath);

        if ($size > $streamed) {
            $handle = fopen($logPath, 'rb');

            if ($handle !== false) {
                fseek($handle, $streamed);
                $new = (string) stream_get_contents($handle);
                fclose($handle);

                fwrite(STDOUT, $new);
                $streamed += strlen($new);
            }
        }

        if (! proc_get_status($process)['running']) {
            break;
        }

        usleep(200_000);
    }

    $code = proc_close($process);

    // Anything written between the final poll and exit.
    clearstatcache(true, $logPath);
    $output = (string) @file_get_contents($logPath);

    if (strlen($output) > $streamed) {
        fwrite(STDOUT, substr($output, $streamed));
    }

    return ['code' => $code, 'output' => $output];
};

$php = PHP_BINARY;

fwrite(STDOUT, "[test-baseline] database : {$database}\n");
fwrite(STDOUT, "[test-baseline] logs     : {$logDirectory}\n");
fwrite(STDOUT, "[test-baseline] php      : " . PHP_VERSION . "\n");

// ---------------------------------------------------------------------
// 2. Clear stale caches
// ---------------------------------------------------------------------

foreach (['config:clear', 'cache:clear', 'view:clear', 'route:clear'] as $command) {
    $step = $runStep("artisan {$command}", [$php, 'artisan', $command]);

    // cache:clear can legitimately fail when no cache table/driver is
    // configured for this environment; it must never block a test run.
    if ($step['code'] !== 0 && $command !== 'cache:clear') {
        fwrite(STDERR, "[test-baseline] {$command} failed.\n");
        exit(EXIT_STEP_FAILED);
    }
}

// ---------------------------------------------------------------------
// 3. Migrate — into the validated database and nothing else
// ---------------------------------------------------------------------

$migrate = $runStep('artisan migrate --force', [$php, 'artisan', 'migrate', '--force']);

if ($migrate['code'] !== 0) {
    fwrite(STDERR, "[test-baseline] Migrations failed against [{$database}].\n");
    exit(EXIT_STEP_FAILED);
}

// ---------------------------------------------------------------------
// 4. Assets the suite genuinely needs
// ---------------------------------------------------------------------

if (! $skipAssets) {
    $manifestPath = $root . '/public/mix-manifest.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true)
        : null;

    // Every entry point webpack.mix.js declares for js/core must be
    // present. A missing one is not cosmetic: panels/scripts.blade.php
    // throws MixFileNotFoundException while APP_DEBUG is true, so every
    // page-rendering test fails with an error that looks unrelated.
    $requiredEntries = [
        '/js/core/app-menu.js',
        '/js/core/theme-tokens.js',
        '/js/core/app.js',
        '/js/core/scripts.js',
    ];

    $missing = [];

    foreach ($requiredEntries as $entry) {
        if (! is_array($manifest) || ! isset($manifest[$entry])) {
            $missing[] = $entry;
        }
    }

    if ($missing !== []) {
        fwrite(STDERR,
            "[test-baseline] public/mix-manifest.json is missing required entries:\n"
            . '  - ' . implode("\n  - ", $missing) . "\n"
            . "Build the assets first (npm ci && npm run production), then re-run.\n"
            . "Never hand-edit the manifest: it must be produced by the build.\n"
        );
        exit(EXIT_STEP_FAILED);
    }
}

// ---------------------------------------------------------------------
// 5. The suite itself
// ---------------------------------------------------------------------

$phpunit = $root . '/vendor/bin/phpunit';

if (! is_file($phpunit)) {
    fwrite(STDERR, "[test-baseline] vendor/bin/phpunit is missing. Run composer install first.\n");
    exit(EXIT_STEP_FAILED);
}

$tests = $runStep('phpunit', array_merge([$php, $phpunit], $phpunitArgs));

// A command that exits zero having discovered nothing is a failure, not a
// pass (AGENTS.md, "Reject zero-test success").
//
// PHPUnit colours its summary, so the ANSI escapes are stripped first —
// otherwise "Tests: 34" begins with an escape sequence and a line-anchored
// match never sees it, turning every successful run into a false "no
// tests" failure.
$plainOutput = (string) preg_replace('/\e\[[0-9;]*m/', '', $tests['output']);

if (preg_match('/^(OK|Tests:)/m', $plainOutput) !== 1
    || str_contains($plainOutput, 'No tests executed')) {
    fwrite(STDERR, "[test-baseline] The suite reported no executed tests. Treating this as a failure.\n");
    exit(EXIT_NO_TESTS);
}

if ($tests['code'] !== 0) {
    fwrite(STDERR, "\n[test-baseline] FAILED — see {$logDirectory}\n");
    exit($tests['code']);
}

fwrite(STDOUT, "\n[test-baseline] PASSED — logs in {$logDirectory}\n");
exit(EXIT_OK);
