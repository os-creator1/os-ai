<?php

/**
 * RFC-003 M1B Slice 4B dedicated runner for the workspace-enforcement
 * PHPUnit group. Creates a disposable, uniquely named
 * ultimatesms_testing_enforcement_<pid>_<hex> database — deliberately
 * handed to the child unmigrated, since each enforcement test prepares its
 * own post-migration-5/pre-migration-6 schema via
 * EnforcementWorkspaceTestCase::setUp() — runs only the
 * #[Group('workspace-enforcement')] test classes against it in a
 * genuinely separate PHPUnit child process, then drops the database.
 *
 * The active base database — the canonical ultimatesms_testing database or
 * any Tests\Support\TestDatabaseSafety-validated disposable sibling — is
 * only ever read to verify it is the resolved base connection; it is never
 * migrated, dropped, renamed or reconfigured.
 *
 * EXPECTED_TEST_DATABASE is a mandatory environment handoff, exactly like
 * every other guarded subprocess runner in this repository: this is a
 * standalone entry point with no parent PHPUnit test to inherit an
 * already-verified connection from, so the caller (a human, a script, or a
 * CI workflow step) must set EXPECTED_TEST_DATABASE explicitly before
 * invoking this file. The autonomous GitHub gate provides the canonical
 * handoff in its own workflow environment (.github/workflows/
 * ai-subscription-gate.yml) — routes 1 and 2 remain canonical-only.
 *
 * Usage: EXPECTED_TEST_DATABASE=ultimatesms_testing php tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;
use Tests\Support\TestDatabaseSafety;

const WRONG_DATABASE_EXIT_CODE = 3;
const SETUP_OR_CLEANUP_FAILURE_EXIT_CODE = 5;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run the workspace enforcement suite: EXPECTED_TEST_DATABASE was not set by the caller. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run the workspace enforcement suite: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$projectRoot = realpath(__DIR__ . '/../../../../');

try {
    $exitCode = TemporaryTestDatabase::withEnforcementDatabase(
        function (string $databaseName) use ($projectRoot) {
            fwrite(STDOUT, "Enforcement database: {$databaseName}\n");

            $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
            $childEnv = [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => $databaseName,
                'EXPECTED_TEST_DATABASE' => $databaseName,
            ];

            $process = new Process(
                [$phpBinary, $projectRoot . '/vendor/bin/phpunit', '--group', 'workspace-enforcement'],
                $projectRoot,
                $childEnv
            );
            $process->setTimeout(300);
            $process->run(function (string $type, string $buffer): void {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
            });

            return $process->getExitCode();
        }
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Enforcement suite setup/cleanup failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(SETUP_OR_CLEANUP_FAILURE_EXIT_CODE);
}

exit($exitCode ?? SETUP_OR_CLEANUP_FAILURE_EXIT_CODE);
