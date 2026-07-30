<?php

/**
 * RFC-003 M1B Slice 4A dedicated runner for the historical-m1a PHPUnit
 * group. Prepares a disposable, uniquely named
 * ultimatesms_testing_historical_<pid>_<hex> database at the current
 * latest-migration schema state (post-migration-5 today — Slice 4A ships
 * no migration 6), runs only the #[Group('historical-m1a')] test classes
 * against it in a genuinely separate PHPUnit child process, then drops the
 * database.
 *
 * The primary ultimatesms_testing database is only ever read to verify it
 * is the resolved base connection — it is never migrated backward,
 * migrated forward here, dropped, renamed or reconfigured.
 *
 * Usage: php tests/Feature/Workspace/Support/run_historical_m1a_suite.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;

const WRONG_DATABASE_EXIT_CODE = 3;
const SETUP_OR_CLEANUP_FAILURE_EXIT_CODE = 5;

function assertHistoricalSchemaState(string $connectionName, string $databaseName): void
{
    $connection = DB::connection($connectionName);

    $column = $connection->selectOne(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'workspace_id'",
        [$databaseName]
    );

    if ($column === null || $column->IS_NULLABLE !== 'YES') {
        throw new RuntimeException('Prepared historical database does not have a nullable businesses.workspace_id.');
    }

    $foreignKeyCount = $connection->selectOne(
        "SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'businesses'
         AND COLUMN_NAME = 'workspace_id' AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'",
        [$databaseName]
    )->total;

    $indexCount = $connection->selectOne(
        "SELECT COUNT(DISTINCT INDEX_NAME) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'businesses'
         AND INDEX_NAME IN ('businesses_workspace_id_index', 'businesses_workspace_id_status_index')",
        [$databaseName]
    )->total;

    if ($foreignKeyCount > 0 || $indexCount > 0) {
        throw new RuntimeException('Prepared historical database unexpectedly already has final Workspace FK/index constraints.');
    }

    $migrationSixApplied = $connection->table('migrations')
        ->where('migration', 'like', '%enforce_business_workspace_constraint%')
        ->exists();

    if ($migrationSixApplied) {
        throw new RuntimeException('Prepared historical database unexpectedly already has enforce_business_workspace_constraint applied.');
    }
}

$resolvedDatabase = DB::connection()->getDatabaseName();

if ($resolvedDatabase !== 'ultimatesms_testing') {
    fwrite(STDERR, sprintf(
        "Refusing to run the historical M1A suite: resolved database is [%s], expected [ultimatesms_testing].\n",
        $resolvedDatabase
    ));
    exit(WRONG_DATABASE_EXIT_CODE);
}

$projectRoot = realpath(__DIR__ . '/../../../../');

try {
    $exitCode = TemporaryTestDatabase::withHistoricalDatabase(
        function (string $databaseName, string $connectionName) use ($projectRoot) {
            fwrite(STDOUT, "Historical database: {$databaseName}\n");

            // Steps 6/7: migrate through the current latest migration
            // against the named connection only — never by mutating this
            // already-booted process's own DB_DATABASE/default connection.
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

            assertHistoricalSchemaState($connectionName, $databaseName);

            // Steps 8/9: a genuinely separate PHPUnit child process, its
            // database selection driven entirely by its own fresh
            // bootstrap reading these explicit environment variables.
            $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
            $childEnv = [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => $databaseName,
                'EXPECTED_TEST_DATABASE' => $databaseName,
            ];

            $process = new Process(
                [$phpBinary, $projectRoot . '/vendor/bin/phpunit', '--group', 'historical-m1a'],
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
    fwrite(STDERR, 'Historical suite setup/cleanup failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(SETUP_OR_CLEANUP_FAILURE_EXIT_CODE);
}

exit($exitCode ?? SETUP_OR_CLEANUP_FAILURE_EXIT_CODE);
