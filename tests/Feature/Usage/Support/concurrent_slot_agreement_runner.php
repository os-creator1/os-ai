<?php

/**
 * M4 contract §23 (Correction Round 2 §E.7) — standalone runner invoked as
 * a separate OS process by SlotAgreementConcurrencyTest, so its forced-race
 * scenarios exercise genuinely independent database connections racing for
 * the same row lock — something a single PHPUnit process cannot do on its
 * own. Boots the app in the testing environment so it shares the same
 * ultimatesms_testing database and .env.testing credentials as the parent
 * PHPUnit process, following
 * tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php's
 * exact bootstrap/database-guard/exit-code/hold-then shape — that file is
 * scoped to Entitlement/Workspace manager methods and is not M4-owned, so
 * this is a new file mirroring its pattern for M4's own manager methods
 * rather than an extension of it.
 *
 * Usage: php concurrent_slot_agreement_runner.php <mode> <args...>
 *
 * Modes:
 *   perform-verified-allocation <agreementId>
 *   allocate-slot-agreement-admin <agreementId> <actorUserId> <reason>
 *   retry-slot-renewal-owner <chargeId> <actorUserId> <outcome>
 *     <outcome> configures the fake gateway's own confirmPaymentIntent()
 *     wildcard outcome ('succeeded'|'declined'|'requires_action') before
 *     calling retrySlotRenewalAsOwner() — this process's own fresh
 *     FakePaymentProviderGateway instance is unreachable from the parent
 *     PHPUnit process's memory, so the desired outcome is passed
 *     explicitly rather than pre-configured.
 *   retry-slot-renewal-admin <chargeId> <actorUserId> <reason> <outcome>
 *
 *   hold-then <lockSpecs> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Identical primitive to concurrent_business_slot_runner.php's own:
 *     opens one real transaction, takes an explicit `SELECT ... FOR UPDATE`
 *     lock on each row named in <lockSpecs> (a '|'-delimited list of
 *     'table:column:id' triples, acquired strictly in the order given),
 *     prints "LOCKED" (flushed) so the parent test can confirm every
 *     listed lock is genuinely held before starting the racing "waiter"
 *     process, sleeps for <holdSeconds>, then runs <delegateMode> (any
 *     mode above) inside that SAME transaction. <lockSpecs> must be the
 *     exact prefix of <delegateMode>'s own real production lock sequence.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const EXPECTED_DATABASE = 'ultimatesms_testing';
const WRONG_DATABASE_EXIT_CODE = 3;

$resolvedDatabase = Illuminate\Support\Facades\DB::connection()->getDatabaseName();

if ($resolvedDatabase !== EXPECTED_DATABASE) {
    fwrite(STDERR, sprintf(
        "Refusing to run: resolved database is [%s], expected [%s]. Aborting before any database write.\n",
        $resolvedDatabase,
        EXPECTED_DATABASE
    ));
    exit(WRONG_DATABASE_EXIT_CODE);
}

$app->instance(
    App\Library\Usage\Contracts\PaymentProviderGateway::class,
    new App\Library\Usage\FakePaymentProviderGateway()
);

/**
 * Runs a single mode's real production logic. Shared by the top-level
 * dispatch below and by the `hold-then` wrapper, so both a plain (unheld)
 * invocation and a deliberately lock-held invocation execute the exact
 * same real manager calls — never a test-only stand-in.
 */
function runMode(string $mode, array $argv, $app): void
{
    switch ($mode) {
        case 'perform-verified-allocation':
            [, , $agreementId] = $argv;
            $agreement = App\Models\AdditionalBusinessSlotAgreement::findOrFail((int) $agreementId);
            $assignment = $app->make(App\Library\Usage\UsageBillingCheckoutManager::class)->performVerifiedAllocation($agreement);
            fwrite(STDOUT, sprintf("OK additional_business_slots=%d\n", $assignment->additional_business_slots));

            return;

        case 'allocate-slot-agreement-admin':
            [, , $agreementId, $actorUserId, $reason] = $argv;
            $agreement = App\Models\AdditionalBusinessSlotAgreement::findOrFail((int) $agreementId);
            $assignment = $app->make(App\Library\Usage\UsageBillingCheckoutManager::class)->allocateSlotAgreementAsAdministrator(
                $agreement,
                (int) $actorUserId,
                $reason,
            );
            fwrite(STDOUT, sprintf("OK additional_business_slots=%d\n", $assignment->additional_business_slots));

            return;

        case 'retry-slot-renewal-owner':
            [, , $chargeId, $actorUserId, $outcome] = $argv;
            $gateway = $app->make(App\Library\Usage\Contracts\PaymentProviderGateway::class);
            $gateway->confirmPaymentIntentOutcomes['*'] = $outcome;
            $charge = App\Models\AdditionalBusinessSlotRenewalCharge::findOrFail((int) $chargeId);
            $result = $app->make(App\Library\Usage\UsageBillingCheckoutManager::class)->retrySlotRenewalAsOwner($charge, (int) $actorUserId);
            fwrite(STDOUT, sprintf("OK state=%s\n", $result->state->value));

            return;

        case 'retry-slot-renewal-admin':
            [, , $chargeId, $actorUserId, $reason, $outcome] = $argv;
            $gateway = $app->make(App\Library\Usage\Contracts\PaymentProviderGateway::class);
            $gateway->confirmPaymentIntentOutcomes['*'] = $outcome;
            $charge = App\Models\AdditionalBusinessSlotRenewalCharge::findOrFail((int) $chargeId);
            $result = $app->make(App\Library\Usage\UsageBillingCheckoutManager::class)->retrySlotRenewalAsAdministrator($charge, (int) $actorUserId, $reason);
            fwrite(STDOUT, sprintf("OK state=%s\n", $result->state->value));

            return;

        default:
            fwrite(STDERR, "Unknown mode [{$mode}].\n");
            exit(2);
    }
}

$mode = $argv[1] ?? null;

try {
    if ($mode === 'hold-then') {
        // hold-then <lockSpecs> <holdSeconds> <delegateMode> <delegateArgs...>
        [, , $lockSpecsRaw, $holdSeconds, $delegateMode] = $argv;
        $delegateArgv = array_merge([$argv[0], $delegateMode], array_slice($argv, 5));

        $lockSpecs = array_map(
            static fn (string $spec) => explode(':', $spec, 3),
            explode('|', $lockSpecsRaw)
        );

        Illuminate\Support\Facades\DB::transaction(function () use ($lockSpecs, $holdSeconds, $delegateMode, $delegateArgv, $app) {
            foreach ($lockSpecs as [$lockTable, $lockColumn, $lockId]) {
                Illuminate\Support\Facades\DB::table($lockTable)->where($lockColumn, $lockId)->lockForUpdate()->first();
            }

            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);

            if ((int) $holdSeconds > 0) {
                sleep((int) $holdSeconds);
            }

            runMode($delegateMode, $delegateArgv, $app);
        });

        exit(0);
    }

    runMode((string) $mode, $argv, $app);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
