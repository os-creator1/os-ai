<?php

/**
 * Standalone runner invoked as a separate OS process by
 * WorkspaceBackfillV1ConcurrencyTest, so the real cross-process
 * concurrency test exercises two genuinely independent database
 * connections racing for the same users-row lock — something a single
 * PHPUnit process cannot do on its own. Boots the app in the testing
 * environment so it shares the same ultimatesms_testing database and
 * .env.testing credentials as the parent PHPUnit process.
 *
 * Usage: php concurrent_backfill_runner.php <plain|slow> <holdSeconds> <customerId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
