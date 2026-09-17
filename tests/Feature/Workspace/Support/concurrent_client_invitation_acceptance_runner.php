<?php

/**
 * Standalone runner invoked as a separate OS process by
 * AgencyClientProvisioningConcurrencyTest, so the real cross-process race
 * required by Contract 07's final correction round (item 4) exercises two
 * genuinely independent database connections both trying to accept the
 * SAME Pending invitation — something a single PHPUnit process cannot do
 * on its own, and something an open RefreshDatabase transaction would hide
 * even if it could (the same rationale AgencyClientRelationshipConcurrencyTest
 * already records for the analogous Contract 01 race).
 *
 * Boots the app in the testing environment so it shares the same database
 * and .env.testing credentials as the parent PHPUnit process, and
 * independently re-verifies its own resolved database connection against
 * EXPECTED_TEST_DATABASE before touching anything — mirroring
 * concurrent_agency_client_relationship_runner.php's own safety check
 * verbatim.
 *
 * Exit codes:
 *   0  this process accepted the invitation for real (prints the created
 *      Client Workspace id)
 *   5  this process lost the race — the invitation was no longer Pending
 *      by the time this process's own transaction reached it, refused by
 *      InvalidClientInvitationClaimException rather than a raw unique-
 *      index violation
 *   3  refused to run against an unexpected database
 *   1  anything else, with the exception class on STDERR
 *
 * "WAITING" is printed immediately before accept() is entered, and every
 * result line carries elapsed_ms — the same two-line timing-evidence
 * protocol concurrent_agency_client_relationship_runner.php uses, for the
 * same reason (performance_schema.data_locks needs a privilege the
 * disposable test database user does not have).
 *
 * Usage: php concurrent_client_invitation_acceptance_runner.php <invitationUid> <plaintextToken> <acceptingUserId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;
const NOT_PENDING_EXIT_CODE = 5;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run AgencyClientProvisioningManager: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run AgencyClientProvisioningManager: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

[, $invitationUid, $plaintextToken, $acceptingUserId] = $argv;

$user = App\Models\User::query()->find((int) $acceptingUserId);

if ($user === null) {
    fwrite(STDERR, "The accepting User handed down by the parent test does not exist.\n");
    exit(1);
}

// Everything that could slow the first attempt down — autoloading, the
// container, the User read — has already happened, so the parent's "both
// children are in accept()" signal means exactly that.
fwrite(STDOUT, "WAITING\n");

$startedAt = microtime(true);

try {
    $manager = $app->make(App\Library\Workspace\AgencyClientProvisioningManager::class);

    $invitation = $manager->accept($user, $invitationUid, $plaintextToken);

    fwrite(STDOUT, sprintf(
        "OK created_client_workspace_id=%d elapsed_ms=%d\n",
        $invitation->created_client_workspace_id,
        (int) round((microtime(true) - $startedAt) * 1000),
    ));
    exit(0);
} catch (App\Exceptions\Workspace\InvalidClientInvitationClaimException $e) {
    fwrite(STDOUT, sprintf(
        "NOT_PENDING reason=%s elapsed_ms=%d\n",
        $e->reason,
        (int) round((microtime(true) - $startedAt) * 1000),
    ));
    exit(NOT_PENDING_EXIT_CODE);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
