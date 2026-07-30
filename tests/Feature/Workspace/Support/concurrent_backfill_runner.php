<?php

/**
 * Standalone runner invoked as a separate OS process by
 * WorkspaceBackfillV1ConcurrencyTest, so the real cross-process
 * concurrency test exercises two genuinely independent database
 * connections racing for the same users-row lock — something a single
 * PHPUnit process cannot do on its own. Boots the app in the testing
 * environment so it shares the same database and .env.testing credentials
 * as the parent PHPUnit process — which, since RFC-003 M1B Slice 4A, is
 * either the primary ultimatesms_testing database (when this script is
 * exercised directly) or a generated ultimatesms_testing_historical_*
 * database (when the parent test runs inside the isolated historical
 * suite) — never an arbitrary caller-supplied value.
 *
 * Before doing anything else, this process independently re-verifies its
 * own resolved database connection name against EXPECTED_TEST_DATABASE,
 * forwarded explicitly by the parent test's own already-verified
 * environment — APP_ENV=testing alone is not proof enough, since a stale
 * bootstrap/cache/config.php would make Laravel skip .env resolution
 * entirely and silently reuse whatever database was baked into that
 * cache. The parent PHPUnit process asserts its own connection
 * separately, but that assertion cannot see what a wholly independent
 * child process resolves for itself.
 *
 * Usage: php concurrent_backfill_runner.php <plain|slow> <holdSeconds> <customerId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Tests\Feature\Workspace\Support\TemporaryTestDatabase;

const WRONG_DATABASE_EXIT_CODE = 3;

const PRIMARY_TEST_DATABASE = 'ultimatesms_testing';

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run WorkspaceBackfillV1: EXPECTED_TEST_DATABASE is not set. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$isPrimaryTestDatabase = $expectedDatabase === PRIMARY_TEST_DATABASE;
$isHistoricalTemporaryDatabase = TemporaryTestDatabase::isValidHistoricalName($expectedDatabase);

if (! $isPrimaryTestDatabase && ! $isHistoricalTemporaryDatabase) {
    fwrite(STDERR, sprintf(
        "Refusing to run WorkspaceBackfillV1: EXPECTED_TEST_DATABASE [%s] is neither [%s] nor a valid historical temporary database name. Aborting before any database write.\n",
        $expectedDatabase,
        PRIMARY_TEST_DATABASE
    ));
    exit(WRONG_DATABASE_EXIT_CODE);
}

$resolvedDatabase = Illuminate\Support\Facades\DB::connection()->getDatabaseName();

if ($resolvedDatabase !== $expectedDatabase) {
    fwrite(STDERR, sprintf(
        "Refusing to run WorkspaceBackfillV1: resolved database is [%s], expected [%s]. Aborting before any database write.\n",
        $resolvedDatabase,
        $expectedDatabase
    ));
    exit(WRONG_DATABASE_EXIT_CODE);
}

[, $mode, $holdSeconds] = $argv;

$action = $mode === 'slow'
    ? new Tests\Feature\Workspace\Support\SlowWorkspaceBackfillV1((float) $holdSeconds)
    : new App\Library\Workspace\Migration\WorkspaceBackfillV1();

try {
    $result = $action->run();
    fwrite(STDOUT, sprintf(
        "OK created=%d reused=%d assigned=%d\n",
        $result->workspacesCreated,
        $result->workspacesReused,
        $result->businessesAssigned
    ));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
