<?php

/**
 * M4 contract §23 (Correction Round 2 §E.2/§E.7) — standalone runner
 * invoked as a separate OS process by SlotAgreementConcurrencyTest, so its
 * forced-race scenarios exercise genuinely independent database
 * connections racing for the same row lock — something a single PHPUnit
 * process cannot do on its own. Boots the app in the testing environment
 * so it shares the same ultimatesms_testing database and .env.testing
 * credentials as the parent PHPUnit process, following
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
 *   retry-slot-renewal-owner <chargeId> <actorUserId> <outcome> [<barrierFile> <expectedArrivals> <timeoutSeconds>]
 *     <outcome> configures the fake gateway's own confirmPaymentIntent()
 *     wildcard outcome ('succeeded'|'declined'|'requires_action') before
 *     calling retrySlotRenewalAsOwner() — this process's own fresh
 *     FakePaymentProviderGateway instance is unreachable from the parent
 *     PHPUnit process's memory, so the desired outcome is passed
 *     explicitly rather than pre-configured.
 *
 *     When the three trailing barrier arguments are given, the gateway is
 *     wrapped in BarrierGatedPaymentProviderGateway (below): a deterministic
 *     forced same-ordinal race, proving driveRenewalChargeRetry()'s own
 *     Phase A (lock/read/validate/compute the attempted ordinal, commit,
 *     release the row lock) genuinely lets two independent racing
 *     processes both reach the identical ordinal before either applies a
 *     result — never a hold-then wrapping the *entire* retry call, which
 *     would force strict before/after sequencing and make each process
 *     legitimately compute a *different* ordinal instead.
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
 * Decorates a FakePaymentProviderGateway, delaying confirmPaymentIntent()
 * until a deterministic cross-process file barrier confirms every
 * expected racer has already reached this exact call — i.e. each has
 * already committed and released Phase A's own short lock/read/validate/
 * compute-ordinal transaction, and is now holding the identical attempted
 * ordinal's idempotency key, waiting to receive its provider result. The
 * barrier file itself doubles as the audit trail: one line per arrival,
 * each line the exact idempotency key that arrival supplied, so the
 * parent test can assert every racer computed the identical key. A
 * Windows-compatible, dependency-free flock()-based file lock guards each
 * read/increment; a bounded timeout means a broken barrier degrades to
 * "proceed anyway" rather than hanging the suite. Every other interface
 * method delegates straight through, unchanged.
 */
class BarrierGatedPaymentProviderGateway implements App\Library\Usage\Contracts\PaymentProviderGateway
{
    public function __construct(
        private readonly App\Library\Usage\FakePaymentProviderGateway $inner,
        private readonly string $barrierFile,
        private readonly int $expectedArrivals,
        private readonly float $timeoutSeconds,
    ) {
    }

    public function confirmPaymentIntent(string $providerPaymentIntentId, string $providerPaymentMethodId, string $idempotencyKey): App\Library\Usage\PaymentIntentResult
    {
        $this->recordArrival($idempotencyKey);
        $this->waitForAllArrivals();

        return $this->inner->confirmPaymentIntent($providerPaymentIntentId, $providerPaymentMethodId, $idempotencyKey);
    }

    private function recordArrival(string $idempotencyKey): void
    {
        $handle = fopen($this->barrierFile, 'c+');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        fseek($handle, 0, SEEK_END);
        fwrite($handle, $idempotencyKey."\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function waitForAllArrivals(): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->countArrivals() >= $this->expectedArrivals) {
                return;
            }

            usleep(20_000);
        }
    }

    private function countArrivals(): int
    {
        if (! file_exists($this->barrierFile)) {
            return 0;
        }

        $handle = fopen($this->barrierFile, 'r');

        if ($handle === false) {
            return 0;
        }

        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return count(array_filter(explode("\n", trim($contents))));
    }

    public function createOrRetrieveCustomer(?string $existingProviderCustomerId, string $idempotencyKey): App\Library\Usage\ProviderCustomerResult
    {
        return $this->inner->createOrRetrieveCustomer($existingProviderCustomerId, $idempotencyKey);
    }

    public function createSetupIntent(string $providerCustomerId, string $idempotencyKey): App\Library\Usage\SetupIntentResult
    {
        return $this->inner->createSetupIntent($providerCustomerId, $idempotencyKey);
    }

    public function retrieveSetupIntent(string $providerSetupIntentId): App\Library\Usage\SetupIntentResult
    {
        return $this->inner->retrieveSetupIntent($providerSetupIntentId);
    }

    public function retrievePaymentMethod(string $providerPaymentMethodId): App\Library\Usage\PaymentMethodResult
    {
        return $this->inner->retrievePaymentMethod($providerPaymentMethodId);
    }

    public function detachPaymentMethod(string $providerPaymentMethodId): void
    {
        $this->inner->detachPaymentMethod($providerPaymentMethodId);
    }

    public function createOffSessionPaymentIntent(
        string $providerCustomerId,
        string $providerPaymentMethodId,
        int $amountMinorUnits,
        string $currencyCode,
        string $idempotencyKey,
        array $metadata,
    ): App\Library\Usage\PaymentIntentResult {
        return $this->inner->createOffSessionPaymentIntent($providerCustomerId, $providerPaymentMethodId, $amountMinorUnits, $currencyCode, $idempotencyKey, $metadata);
    }

    public function retrievePaymentIntent(string $providerPaymentIntentId): App\Library\Usage\PaymentIntentResult
    {
        return $this->inner->retrievePaymentIntent($providerPaymentIntentId);
    }

    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): App\Library\Usage\WebhookVerificationResult
    {
        return $this->inner->verifyWebhookSignature($rawBody, $signatureHeader, $webhookSecret);
    }

    public function createCheckoutSession(
        string $providerCustomerId,
        int $amountMinorUnits,
        string $currencyCode,
        string $lineItemName,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): App\Library\Usage\CheckoutSessionResult {
        return $this->inner->createCheckoutSession($providerCustomerId, $amountMinorUnits, $currencyCode, $lineItemName, $successUrl, $cancelUrl, $idempotencyKey, $metadata);
    }

    public function retrieveCheckoutSession(string $providerCheckoutSessionId): App\Library\Usage\CheckoutSessionResult
    {
        return $this->inner->retrieveCheckoutSession($providerCheckoutSessionId);
    }
}

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

            if (isset($argv[5], $argv[6], $argv[7])) {
                $gateway = new BarrierGatedPaymentProviderGateway($gateway, (string) $argv[5], (int) $argv[6], (float) $argv[7]);
                $app->instance(App\Library\Usage\Contracts\PaymentProviderGateway::class, $gateway);
            }

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
