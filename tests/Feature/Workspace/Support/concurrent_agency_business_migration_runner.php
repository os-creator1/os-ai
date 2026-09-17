<?php

/**
 * Standalone runner invoked as a separate OS process by
 * AgencyBusinessMigrationV1ConcurrencyTest, so the real cross-process race
 * required by Implementation Contract 10's concurrency requirement
 * exercises two genuinely independent database connections both trying to
 * migrate the SAME legacy client Business out of the same Agency Workspace
 * — something a single PHPUnit process cannot do on its own, and something
 * an open RefreshDatabase transaction would hide even if it could (the same
 * rationale AgencyClientRelationshipConcurrencyTest and
 * AgencyClientProvisioningConcurrencyTest already record for their own
 * analogous races).
 *
 * Boots the app in the testing environment so it shares the same database
 * and .env.testing credentials as the parent PHPUnit process, and
 * independently re-verifies its own resolved database connection against
 * EXPECTED_TEST_DATABASE before touching anything — mirroring the other
 * concurrency runners' own safety check verbatim.
 *
 * Exit codes:
 *   0  this process migrated the Business for real (prints the created
 *      Client Workspace id)
 *   6  this process lost the race — by the time this process's own
 *      transaction reached the Business, it was no longer a candidate
 *      (already moved out of the Agency Workspace)
 *   3  refused to run against an unexpected database
 *   1  anything else, with the exception class on STDERR
 *
 * "WAITING" is printed immediately before run() is entered, and every
 * result line carries elapsed_ms — the same two-line timing-evidence
 * protocol the other concurrency runners use.
 *
 * Usage: php concurrent_agency_business_migration_runner.php <agencyWorkspaceId> <operatorUserId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;
const ALREADY_MIGRATED_EXIT_CODE = 6;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run AgencyBusinessMigrationV1: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run AgencyBusinessMigrationV1: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

[, $agencyWorkspaceId, $operatorUserId] = $argv;

fwrite(STDOUT, "WAITING\n");

$startedAt = microtime(true);

try {
    $migration = $app->make(App\Library\Workspace\Migration\AgencyBusinessMigrationV1::class);

    $report = $migration->run((int) $operatorUserId, false, [(int) $agencyWorkspaceId]);
    $businessReport = $report['agencies'][0]['businesses'][0] ?? null;

    if ($businessReport === null) {
        fwrite(STDERR, "No Business reported for Agency Workspace [{$agencyWorkspaceId}].\n");
        exit(1);
    }

    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

    if ($businessReport['status'] === 'migrated') {
        fwrite(STDOUT, sprintf(
            "OK client_workspace_id=%d relationship_id=%d elapsed_ms=%d\n",
            $businessReport['client_workspace_id'],
            $businessReport['relationship_id'],
            $elapsedMs,
        ));
        exit(0);
    }

    if ($businessReport['status'] === 'already_migrated') {
        fwrite(STDOUT, sprintf("ALREADY_MIGRATED elapsed_ms=%d\n", $elapsedMs));
        exit(ALREADY_MIGRATED_EXIT_CODE);
    }

    fwrite(STDERR, 'Unexpected status: ' . $businessReport['status'] . ' reason=' . ($businessReport['reason'] ?? 'none') . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
