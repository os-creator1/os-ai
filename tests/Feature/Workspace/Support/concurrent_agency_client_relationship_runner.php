<?php

/**
 * Standalone runner invoked as a separate OS process by
 * AgencyClientRelationshipConcurrencyTest, so the real cross-process race
 * required by Implementation Contract 01 §13 exercises two genuinely
 * independent database connections both trying to manage the SAME Client
 * Workspace — something a single PHPUnit process cannot do on its own.
 *
 * Boots the app in the testing environment so it shares the same database
 * and .env.testing credentials as the parent PHPUnit process — the canonical
 * ultimatesms_testing database or any Tests\Support\TestDatabaseSafety-
 * validated disposable sibling the parent hands down.
 *
 * Before doing anything else, this process independently re-verifies its own
 * resolved database connection name against EXPECTED_TEST_DATABASE, forwarded
 * explicitly by the parent test's own already-verified environment —
 * APP_ENV=testing alone is not proof enough, since a stale
 * bootstrap/cache/config.php would make Laravel skip .env resolution entirely
 * and silently reuse whatever database was baked into that cache. The parent
 * PHPUnit process asserts its own connection separately, but that assertion
 * cannot see what a wholly independent child process resolves for itself.
 *
 * Exit codes are the test's evidence, so they are distinct on purpose:
 *   0  the relationship was established by this process (prints its id)
 *   4  this process lost the race and was refused cleanly, by the domain
 *      rule rather than by a raw unique-index violation
 *   3  refused to run against an unexpected database
 *   1  anything else, with the exception class on STDERR
 *
 * Two lines of timing evidence let the parent prove the race genuinely
 * overlapped without needing performance_schema privileges the test database
 * user does not have: "WAITING" is printed immediately BEFORE create() is
 * entered, and every result line carries elapsed_ms, the wall time create()
 * itself took. A process that really queued behind the parent's row lock
 * cannot report an elapsed time shorter than the parent's remaining hold.
 *
 * Usage: php concurrent_agency_client_relationship_runner.php <agencyWorkspaceId> <clientWorkspaceId> <actorUserId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;
const ALREADY_MANAGED_EXIT_CODE = 4;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run AgencyClientRelationshipManager: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run AgencyClientRelationshipManager: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

[, $agencyWorkspaceId, $clientWorkspaceId, $actorUserId] = $argv;

$agencyWorkspace = App\Models\Workspace::query()->find((int) $agencyWorkspaceId);
$clientWorkspace = App\Models\Workspace::query()->find((int) $clientWorkspaceId);

if ($agencyWorkspace === null || $clientWorkspace === null) {
    fwrite(STDERR, "One of the Workspaces handed down by the parent test does not exist.\n");
    exit(1);
}

// Everything that could slow the first attempt down — autoloading, the
// container, the Workspace reads — has already happened, so the parent's
// "both children are in create()" signal means exactly that.
fwrite(STDOUT, "WAITING\n");

$startedAt = microtime(true);

try {
    $relationship = $app->make(App\Library\Workspace\AgencyClientRelationshipManager::class)
        ->create((int) $actorUserId, $agencyWorkspace, $clientWorkspace);

    fwrite(STDOUT, sprintf(
        "OK relationship_id=%d agency_workspace_id=%d elapsed_ms=%d\n",
        $relationship->id,
        $agencyWorkspace->id,
        (int) round((microtime(true) - $startedAt) * 1000),
    ));
    exit(0);
} catch (App\Exceptions\Workspace\ClientWorkspaceAlreadyManagedException $e) {
    fwrite(STDOUT, sprintf(
        "ALREADY_MANAGED client_workspace_id=%d existing_agency_workspace_id=%d elapsed_ms=%d\n",
        $e->clientWorkspaceId,
        $e->existingAgencyWorkspaceId,
        (int) round((microtime(true) - $startedAt) * 1000),
    ));
    exit(ALREADY_MANAGED_EXIT_CODE);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
