<?php

/**
 * Standalone runner invoked as a separate OS process by
 * WorkspaceManagerConcurrencyTest, so the real cross-process concurrency
 * test exercises two genuinely independent database connections racing
 * for the same users-row lock — something a single PHPUnit process cannot
 * do on its own. Boots the app in the testing environment so it shares
 * the same database and .env.testing credentials as the parent PHPUnit
 * process — the canonical ultimatesms_testing database or any
 * Tests\Support\TestDatabaseSafety-validated disposable sibling the
 * parent hands down.
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
 * Deliberately a separate file from concurrent_backfill_runner.php: this
 * exercises the runtime WorkspaceManager resolver, not the historical
 * WorkspaceBackfillV1 migration action, and the two must stay decoupled.
 *
 * Usage: php concurrent_workspace_resolver_runner.php <plain|slow> <holdSeconds> <ownerUserId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run WorkspaceManager: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run WorkspaceManager: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

[, $mode, $holdSeconds, $ownerUserId] = $argv;

$ownerUserId = (int) $ownerUserId;

$manager = $mode === 'slow'
    ? new Tests\Feature\Workspace\Support\SlowWorkspaceManager(
        $app->make(App\Repositories\Contracts\WorkspaceRepository::class),
        $app->make(App\Repositories\Contracts\BusinessRepository::class),
        $app->make(App\Repositories\Contracts\CustomerOnboardingRepository::class),
        $app->make(App\Repositories\Contracts\CustomerRepository::class),
        $app->make(App\Repositories\Contracts\WorkspaceMembershipRepository::class),
        $app->make(App\Repositories\Contracts\WorkspaceMembershipBusinessRepository::class),
        $app->make(App\Repositories\Contracts\WorkspaceTransitionRepository::class),
        $app->make(App\Library\Entitlement\EntitlementManager::class),
        (float) $holdSeconds
    )
    : $app->make(App\Library\Workspace\WorkspaceManager::class);

try {
    $workspace = $manager->resolveLegacyOnboardingWorkspace($ownerUserId);
    fwrite(STDOUT, sprintf("OK workspace_id=%d\n", $workspace->id));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
