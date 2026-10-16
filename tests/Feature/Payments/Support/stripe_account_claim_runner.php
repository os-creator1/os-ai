<?php

/**
 * Standalone runner invoked as a SEPARATE OS PROCESS by
 * StripeAccountClaimConcurrencyTest, so Business/Agency Stripe-account races
 * run on genuinely independent MySQL connections.
 *
 * Same safety model as concurrent_payment_runner.php: the database is
 * re-verified against EXPECTED_TEST_DATABASE before any write; every child
 * busy-waits for a shared start instant and reports `ENTERED <us>`; Stripe is a
 * fake that refuses to run inside a DB transaction (no network under a lock).
 *
 * Exit codes: 0 committed, 4 refused by a DOMAIN rule (the loser), 3 wrong
 * database, 1 anything else (exception class on STDERR).
 *
 * Usage: php stripe_account_claim_runner.php <op> <startAtEpochMicros> <callsFile> <args...>
 *   business   <businessId> <userId> <account>      Business connectExisting
 *   agency     <workspaceId> <userId> <account>     Agency   connectExisting
 *   disconnect <businessId> <userId>                Business disconnect (OAuth revocation)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test.\n");
    exit(3);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . "\n");
    exit(3);
}

$operation = $argv[1] ?? '';
$startAt = (int) ($argv[2] ?? 0);
$callsFile = (string) ($argv[3] ?? '');

$record = static function (string $line) use ($callsFile): void {
    file_put_contents($callsFile, $line . "\n", FILE_APPEND | LOCK_EX);
};

$us = static fn (): int => (int) (microtime(true) * 1_000_000);

// ---- Business-lane Stripe fake: logs deauthorize with a microsecond stamp ----
$business = new class($record, $us) extends Tests\Support\Payments\FakeStripeConnectGateway {
    public function __construct(private readonly Closure $recordLine, private readonly Closure $now)
    {
        $this->detailsSubmitted = true;
        $this->chargesEnabled = true;
        $this->payoutsEnabled = true;
    }

    public function deauthorizeAccount(string $stripeAccountId): void
    {
        if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) {
            throw new LogicException('deauthorizeAccount() ran inside a transaction.');
        }

        // Widen the window between "revoke" and "release" so a racing claimer
        // has every chance to slip in if the ordering were wrong.
        usleep(200_000);
        ($this->recordLine)('deauth|' . ($this->now)() . '|' . $stripeAccountId);
    }

    public function exchangeOAuthCode(string $authorizationCode): App\Library\Payments\OAuthGrant
    {
        return new App\Library\Payments\OAuthGrant($authorizationCode, 'read_write', false);
    }
};
$app->instance(App\Library\Payments\StripeConnectGateway::class, $business);

$agency = new Tests\Support\AgencyBilling\FakeAgencyStripeGateway();
$app->instance(App\Library\AgencyBilling\AgencyStripeGateway::class, $agency);

$waitForStart = static function (int $target) use ($us): void {
    while (($remaining = $target - $us()) > 0) {
        usleep((int) min($remaining - 1_000, 50_000) > 0 ? (int) min($remaining - 1_000, 50_000) : 100);
    }
};

try {
    $run = match ($operation) {
        'business' => static function () use ($app, $argv, $record, $us): void {
            $b = App\Models\Business::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Payments\StripeConnectManager::class)
                ->connectExisting((int) $argv[5], $b, (string) $argv[6], false);
            $record('claimed|' . $us() . '|business|' . $argv[6]);
        },
        'agency' => static function () use ($app, $argv, $agency, $record, $us): void {
            $agency->registerExistingAccount('code-' . $argv[6], (string) $argv[6]);
            $w = App\Models\Workspace::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\AgencyBilling\AgencyStripeConnectManager::class)
                ->connectExisting((int) $argv[5], $w, 'code-' . $argv[6]);
            $record('claimed|' . $us() . '|agency|' . $argv[6]);
        },
        'disconnect' => static function () use ($app, $argv): void {
            $b = App\Models\Business::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Payments\StripeConnectManager::class)->disconnect((int) $argv[5], $b);
        },
        default => throw new InvalidArgumentException("Unknown operation [{$operation}]"),
    };

    $waitForStart($startAt);
    fwrite(STDOUT, 'ENTERED ' . $us() . "\n");
    $run();
    fwrite(STDOUT, "COMMITTED\n");
    exit(0);
} catch (App\Exceptions\Payments\StripeConnectException|App\Exceptions\AgencyBilling\AgencyBillingException $e) {
    fwrite(STDOUT, 'REFUSED ' . $e->reason . "\n");
    exit(4);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
