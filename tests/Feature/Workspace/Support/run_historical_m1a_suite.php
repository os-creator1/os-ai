<?php

/**
 * RFC-003 M1B Slice 4A/4B dedicated runner for both schema-isolated
 * pre-enforcement PHPUnit groups: historical-m1a and
 * workspace-pre-enforcement. Both need the exact same
 * post-migration-5/pre-migration-6 schema state, so one generated,
 * uniquely named ultimatesms_testing_historical_<pid>_<hex> database
 * safely serves both — a second database lifecycle would be pure overhead
 * for two groups with an identical schema requirement. Runs both groups'
 * test classes against it in a genuinely separate PHPUnit child process
 * (verified via `--group historical-m1a --group workspace-pre-enforcement
 * --list-tests` before being relied on here — repeated flags, not a
 * comma-separated value, since PHPUnit 12 removes comma-separated --group
 * support), then drops the database.
 *
 * Since Slice 4B, migration 6 exists and participates in every full
 * migration chain, so this runner migrates the temporary database all the
 * way through (proving migration 6 itself still applies cleanly to a
 * fresh database) and then rolls back every migration from the newest down
 * through migration 6 inclusive (rollbackToPostMigrationFive() — migration
 * 6 is no longer guaranteed to be the newest migration once RFC-003
 * Milestone 2 adds workspace_transitions after it, so the rollback step
 * count is computed by name, never hard-coded) — never assuming the
 * rollback worked without independently re-verifying the resulting schema
 * shape.
 *
 * The primary ultimatesms_testing database is only ever read to verify it
 * is the resolved base connection — it is never migrated, migrated
 * backward, dropped, renamed or reconfigured.
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

/**
 * Migration 6 is no longer guaranteed to be the newest migration in the
 * chain (RFC-003 Milestone 2 adds workspace_transitions after it), so a
 * fixed `--step=1` rollback can no longer be trusted to undo exactly this
 * migration. rollbackToPostMigrationFive() below computes the exact step
 * count instead, by name, so this stays correct regardless of how many
 * later migrations exist.
 */
const MIGRATION_SIX_NAME = '2026_07_30_120006_enforce_business_workspace_constraint';

/**
 * Rolls back every migration from the newest down through migration 6
 * (inclusive), landing on the exact post-migration-5 schema regardless of
 * how many migrations were added after migration 6. The step count is
 * computed from the migrations table itself — never hard-coded.
 */
function rollbackToPostMigrationFive(string $connectionName): void
{
    $connection = DB::connection($connectionName);

    $migrationSix = $connection->table('migrations')->where('migration', MIGRATION_SIX_NAME)->first();

    if ($migrationSix === null) {
        throw new RuntimeException(
            'Refusing to prepare the historical database: migration [' . MIGRATION_SIX_NAME . '] is not applied.'
        );
    }

    $migrationSixCount = $connection->table('migrations')->where('migration', MIGRATION_SIX_NAME)->count();

    if ($migrationSixCount !== 1) {
        throw new RuntimeException(
            'Refusing to prepare the historical database: migration [' . MIGRATION_SIX_NAME
            . "] appears {$migrationSixCount} times in the migrations table."
        );
    }

    $stepCount = $connection->table('migrations')->where('id', '>=', $migrationSix->id)->count();

    if ($stepCount < 1) {
        throw new RuntimeException(
            'Refusing to prepare the historical database: computed an invalid rollback step count.'
        );
    }

    Artisan::call('migrate:rollback', [
        '--database' => $connectionName,
        '--step' => $stepCount,
        '--force' => true,
    ]);
}

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

    $migrationFiveApplied = $connection->table('migrations')
        ->where('migration', 'like', '%backfill_business_workspaces%')
        ->exists();

    if (! $migrationFiveApplied) {
        throw new RuntimeException('Prepared historical database is missing backfill_business_workspaces.');
    }

    $migrationSixApplied = $connection->table('migrations')
        ->where('migration', 'like', '%enforce_business_workspace_constraint%')
        ->exists();

    if ($migrationSixApplied) {
        throw new RuntimeException('Prepared historical database unexpectedly already has enforce_business_workspace_constraint applied.');
    }

    $newerMigrationsCount = $connection->table('migrations')
        ->where('migration', '>', MIGRATION_SIX_NAME)
        ->count();

    if ($newerMigrationsCount > 0) {
        throw new RuntimeException(
            "Prepared historical database still has {$newerMigrationsCount} migration(s) newer than "
            . 'enforce_business_workspace_constraint applied.'
        );
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

            // Step 2: the full migration chain, against the named
            // connection only — never by mutating this already-booted
            // process's own DB_DATABASE/default connection. This also
            // proves migration 6 itself still applies cleanly to a fresh
            // database before immediately rolling it back below.
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

            // Step 3: roll back every migration from the newest down
            // through migration 6 (inclusive) on this temporary database
            // only — ultimatesms_testing is never touched by this call,
            // since $connectionName always points at the generated
            // temporary database. The step count is computed by name
            // (rollbackToPostMigrationFive()), never hard-coded, so this
            // stays correct regardless of how many migrations exist after
            // migration 6.
            rollbackToPostMigrationFive($connectionName);

            // Step 4: never assume the rollback worked — independently
            // re-verify the exact resulting schema shape.
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
                [
                    $phpBinary, $projectRoot . '/vendor/bin/phpunit',
                    '--group', 'historical-m1a',
                    '--group', 'workspace-pre-enforcement',
                ],
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
