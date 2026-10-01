<?php

/**
 * Standalone runner invoked as a SEPARATE OS PROCESS by
 * PaymentConcurrencyTest, so the Payments & Invoices races run on genuinely
 * independent database connections — something one PHPUnit process cannot do,
 * because its own transaction would hide the committed rows the race is about.
 *
 * Boots the app in the testing environment and INDEPENDENTLY re-verifies the
 * database against EXPECTED_TEST_DATABASE before any write (APP_ENV=testing
 * alone is not proof: a stale bootstrap/cache/config.php would make Laravel
 * reuse whatever database was baked in).
 *
 * SYNCHRONIZED START. Every operation takes <startAtEpochMicros> and
 * busy-waits until that instant before entering the engine; two children handed
 * the same instant enter their critical sections within microseconds of each
 * other. Each reports `ENTERED <us>` so the parent can PROVE the two overlapped.
 *
 * NO STRIPE. The gateway is a file-backed fake: it appends one line per
 * provider call to <callsFile> (shared by every child), derives the intent id
 * from the idempotency key the way Stripe's own idempotency does, and refuses
 * to run inside a DB transaction (Contract 17 §7: no network under a lock).
 * Notifications are faked, so no mail is attempted.
 *
 * Exit codes (the parent's evidence):
 *   0  the operation committed (stdout carries its result)
 *   4  refused cleanly by a DOMAIN rule — the contracted loser outcome, never
 *      a raw driver error
 *   3  refused to run against an unexpected database
 *   1  anything else, exception class on STDERR
 *
 * Usage:
 *   php concurrent_payment_runner.php <op> <startAtEpochMicros> <callsFile> <args...>
 *     start     <documentUid> <token>
 *     finalize  <paymentId> <providerStatus>
 *     void      <documentId>
 *     send      <documentId>
 *     edit      <documentId>
 *     addline   <documentId>
 *     resend    <documentId>
 *     webhook   <base64Body>
 *     job       <eventId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;
const REFUSED_EXIT_CODE = 4;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . "\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$operation = $argv[1] ?? '';
$startAt = (int) ($argv[2] ?? 0);
$callsFile = (string) ($argv[3] ?? '');

$record = static function (string $line) use ($callsFile): void {
    file_put_contents($callsFile, $line . "\n", FILE_APPEND | LOCK_EX);
};

Illuminate\Support\Facades\Notification::fake();

foreach ([
    App\Events\DocumentPaymentSucceeded::class,
    App\Events\DocumentPaymentFailed::class,
    App\Events\DocumentFullyPaid::class,
    App\Events\DocumentVoided::class,
] as $eventClass) {
    Illuminate\Support\Facades\Event::listen($eventClass, static function ($event) use ($record, $eventClass): void {
        $record('event|' . class_basename($eventClass) . '|document=' . $event->documentId . '|payment=' . ($event->paymentId ?? 0));
    });
}

$gateway = new class($record) extends Tests\Support\Payments\FakeStripeConnectGateway {
    /** @param \Closure(string): void $record */
    public function __construct(private readonly Closure $recordLine)
    {
    }

    private function assertNoTransaction(string $method): void
    {
        if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) {
            throw new LogicException("Contract 17 §7 violated: {$method}() ran inside a transaction.");
        }
    }

    public function createPaymentIntent(
        string $connectedAccountId,
        int $amountMinor,
        string $currencyCode,
        string $idempotencyKey,
        string $operationId,
        string $description,
        array $metadata = [],
    ): App\Library\Payments\PaymentIntentSnapshot {
        $this->assertNoTransaction('createPaymentIntent');
        ($this->recordLine)('provider|create|' . $idempotencyKey);

        return $this->snapshotFor($connectedAccountId, $amountMinor, $currencyCode, $idempotencyKey, $operationId);
    }

    public function retrievePaymentIntent(string $connectedAccountId, string $providerPaymentIntentId): App\Library\Payments\PaymentIntentSnapshot
    {
        $this->assertNoTransaction('retrievePaymentIntent');
        ($this->recordLine)('provider|retrieve|' . $providerPaymentIntentId);

        $payment = App\Models\BusinessDocumentPayment::query()->where('provider_payment_intent_id', $providerPaymentIntentId)->firstOrFail();

        return $this->snapshotFor($connectedAccountId, (int) $payment->amount_minor, (string) $payment->currency_code,
            'document-payment:' . $payment->uid, (string) $payment->local_idempotency_key);
    }

    private function snapshotFor(string $account, int $amount, string $currency, string $key, string $operationId): App\Library\Payments\PaymentIntentSnapshot
    {
        // Stripe's idempotency: the same key always yields the same intent.
        $intentId = 'pi_' . substr(md5($key), 0, 20);

        return new App\Library\Payments\PaymentIntentSnapshot(
            providerPaymentIntentId: $intentId,
            status: App\Enums\Documents\BusinessDocumentPaymentStatus::Created,
            amountMinor: $amount,
            currencyCode: $currency,
            connectedAccountId: $account,
            operationId: $operationId,
            clientSecret: $intentId . '_secret',
        );
    }
};
$app->instance(App\Library\Payments\StripeConnectGateway::class, $gateway);

/** Busy-wait to the shared instant so every child enters together. */
$waitForStart = static function (int $target): void {
    if ($target <= 0) {
        return;
    }

    while (true) {
        $now = (int) (microtime(true) * 1_000_000);

        if ($now >= $target) {
            return;
        }

        $remaining = $target - $now;

        if ($remaining > 2_000) {
            usleep((int) min($remaining - 1_000, 50_000));
        }
    }
};

try {
    $run = match ($operation) {
        'start' => static function () use ($app, $argv): string {
            $access = $app->make(App\Library\Documents\PublicDocumentGuard::class)->resolve($argv[4], $argv[5]);
            $result = $app->make(App\Library\Payments\PaymentManager::class)->start($access);

            return 'payment_uid=' . $result->paymentUid;
        },
        'finalize' => static function () use ($app, $argv): string {
            $payment = App\Models\BusinessDocumentPayment::query()->findOrFail((int) $argv[4]);
            $connection = App\Models\BusinessStripeConnection::query()->findOrFail($payment->business_stripe_connection_id);
            $disposition = $app->make(App\Library\Payments\PaymentFinalizer::class)->apply($payment, new App\Library\Payments\PaymentIntentSnapshot(
                providerPaymentIntentId: (string) $payment->provider_payment_intent_id,
                status: App\Enums\Documents\BusinessDocumentPaymentStatus::from($argv[5]),
                amountMinor: (int) $payment->amount_minor,
                currencyCode: (string) $payment->currency_code,
                connectedAccountId: (string) $connection->stripe_account_id,
                operationId: (string) $payment->local_idempotency_key,
            ));

            return 'disposition=' . $disposition;
        },
        'void' => static function () use ($app, $argv): string {
            $document = App\Models\BusinessDocument::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Documents\DocumentManager::class)->void($document, 'Voided concurrently');

            return 'voided';
        },
        'send' => static function () use ($app, $argv): string {
            $document = App\Models\BusinessDocument::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Documents\DocumentManager::class)->send($document);

            return 'sent';
        },
        'edit' => static function () use ($app, $argv): string {
            $document = App\Models\BusinessDocument::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Documents\DocumentManager::class)->edit($document, ['content' => ['body' => 'Edited concurrently']]);

            return 'edited';
        },
        'addline' => static function () use ($app, $argv): string {
            $document = App\Models\BusinessDocument::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Documents\DocumentManager::class)->addCustomLine($document, 'Late extra', null, 1, 100);

            return 'line-added';
        },
        'resend' => static function () use ($app, $argv): string {
            $document = App\Models\BusinessDocument::query()->findOrFail((int) $argv[4]);
            $app->make(App\Library\Documents\DocumentManager::class)->resendLink($document);

            return 'resent';
        },
        'webhook' => static function () use ($app, $argv): string {
            $request = Illuminate\Http\Request::create('/stripe/webhook/business-payments', 'POST', [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => 'v1=fake-valid-signature',
                'CONTENT_TYPE' => 'application/json',
            ], base64_decode($argv[4]));
            $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);

            return 'http=' . $response->getStatusCode();
        },
        'job' => static function () use ($app, $argv): string {
            (new App\Jobs\BusinessPayments\ProcessBusinessPaymentEvent((int) $argv[4]))->handle(
                $app->make(App\Library\Payments\PaymentFinalizer::class),
                $app->make(App\Library\Payments\RefundFinalizer::class),
            );

            return 'job-ran';
        },
        default => throw new InvalidArgumentException('Unknown operation ' . $operation),
    };

    $waitForStart($startAt);
    $enteredUs = (int) (microtime(true) * 1_000_000);
    fwrite(STDOUT, "ENTERED {$enteredUs}\n");
    fflush(STDOUT);

    $detail = $run();

    fwrite(STDOUT, 'OK ' . $detail . ' elapsed_us=' . ((int) (microtime(true) * 1_000_000) - $enteredUs) . "\n");
    exit(0);
} catch (Illuminate\Validation\ValidationException|App\Exceptions\Payments\PaymentStartException|App\Exceptions\Documents\DocumentLinkException $e) {
    fwrite(STDOUT, 'REFUSED ' . class_basename($e) . ' ' . json_encode($e instanceof Illuminate\Validation\ValidationException ? $e->errors() : ($e->reason ?? '')) . "\n");
    exit(REFUSED_EXIT_CODE);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
