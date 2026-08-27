<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §16/§25 item 93 — two simultaneous top-up
 * confirmations for the same Business each independently produce exactly
 * one correct ledger effect once confirmed (causal-barrier concurrency,
 * never elapsed-time), plus an unrelated-Business progress assertion.
 *
 * Deliberately does NOT use RefreshDatabase — real cross-process
 * concurrency needs committed rows, mirroring
 * UsageWalletManagerConcurrencyTest's own established precedent exactly.
 * Every fixture row is built directly and tracked for manual cleanup.
 */
class ConcurrentTopUpConcurrencyTest extends TestCase
{
    private array $createdBusinessIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];
    private array $createdPaymentProviderEventIds = [];
    private ?string $runnerPath = null;
    private ?string $signalPath = null;
    private ?int $createdCurrencyId = null;
    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        if (Currency::query()->where('code', 'USD')->count() === 0) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        if ($this->runnerPath !== null && file_exists($this->runnerPath)) {
            @unlink($this->runnerPath);
        }

        if ($this->signalPath !== null && file_exists($this->signalPath)) {
            @unlink($this->signalPath);
        }

        if ($this->createdPaymentProviderEventIds !== []) {
            DB::table('payment_provider_events')->whereIn('id', $this->createdPaymentProviderEventIds)->delete();
        }

        if ($this->createdBusinessIds !== []) {
            DB::table('business_funding_attempt_transitions')->whereIn(
                'funding_attempt_id',
                DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->pluck('id')
            )->delete();
            DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_payment_instruments')->whereIn(
                'provider_customer_id',
                DB::table('payment_provider_customers')->whereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id')
            )->delete();
            DB::table('payment_provider_customers')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('business_payer_assignments')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_billing_receipts')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        if ($this->createdCurrencyId !== null) {
            DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
        }

        parent::tearDown();
    }

    private function createOwnerUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner'.uniqid().'@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;
        DB::table('customers')->insert(['user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * @return array{0: int, 1: int, 2: int} business_id, workspace_id, owner_user_id
     */
    private function createBusinessWithAttachedInstrument(): array
    {
        $ownerId = $this->createOwnerUserId();

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'TopUp WS '.uniqid(), 'owner_user_id' => $ownerId,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdWorkspaceIds[] = $workspaceId;

        $coreCatalogId = DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId, 'workspace_plan_catalog_id' => $coreCatalogId, 'status' => 'active',
            'is_complimentary' => true, 'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $ownerId, 'workspace_id' => $workspaceId,
            'name' => 'TopUp Biz', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'draft',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        $business = \App\Models\Business::find($businessId);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessId);
        DB::table('business_payer_assignments')->insert([
            'business_id' => $businessId, 'payer_type' => 'workspace', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $ownerId);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId($workspaceId);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $ownerId, $setupIntent->providerSetupIntentId);

        return [$businessId, $workspaceId, $ownerId];
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §16.A — a
     * ManualTopUp attempt is now Checkout-Session-backed: initiateTopUp()
     * itself already leaves it in provider_pending (never
     * requires_action, which no longer applies since
     * createOffSessionPaymentIntent() is never called for this purpose),
     * so the prior paymentIntentOutcomes fixture is no longer needed or
     * meaningful here.
     */
    private function createPendingAttempt(int $businessId, int $ownerId, int $amountMicro): int
    {
        $business = \App\Models\Business::find($businessId);
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $ownerId, $amountMicro);

        return $result->fundingAttemptId;
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function baseRunnerPreamble(): string
    {
        $vendorAutoload = base_path('vendor/autoload.php');
        $bootstrapApp = base_path('bootstrap/app.php');
        $escapedVendor = str_replace('\\', '\\\\', $vendorAutoload);
        $escapedBootstrap = str_replace('\\', '\\\\', $bootstrapApp);

        return <<<PHP
<?php
require '{$escapedVendor}';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
putenv('QUEUE_CONNECTION=sync');
\$_ENV['QUEUE_CONNECTION'] = 'sync';
\$_SERVER['QUEUE_CONNECTION'] = 'sync';
\$app = require '{$escapedBootstrap}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

if (config('queue.default') !== 'sync') {
    fwrite(STDERR, "QUEUE_CONNECTION_NOT_SYNC\\n");
    exit(1);
}

app()->instance(
    \App\Library\Usage\Contracts\PaymentProviderGateway::class,
    new \App\Library\Usage\FakePaymentProviderGateway()
);

function waitForSignal(string \$path): void
{
    \$deadline = microtime(true) + 10.0;
    while (! file_exists(\$path)) {
        if (microtime(true) >= \$deadline) {
            fwrite(STDOUT, "TIMEOUT\\n");
            exit(1);
        }
        usleep(5000);
    }
}
PHP;
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §16.A — each
     * child process boots its own fresh FakePaymentProviderGateway, which
     * shares no in-memory state with the parent process that actually
     * created the Checkout Session. This child registers its own
     * deterministic, complete/paid CheckoutSessionResult (and a matching
     * PaymentMethodResult) for the exact persisted Session id before
     * calling confirmAttemptFromReturn() — never relying on the parent's
     * own gateway state, which never existed here at all.
     */
    private function confirmRunnerScript(): string
    {
        return $this->baseRunnerPreamble()."\n".<<<'PHP'

$attemptId = (int) $argv[1];
$signalPath = $argv[2];

fwrite(STDOUT, "WAITING\n");
fflush(STDOUT);
waitForSignal($signalPath);

$attempt = App\Models\BusinessFundingAttempt::find($attemptId);
$manager = app(App\Library\Usage\UsageBillingCheckoutManager::class);
$fake = app(App\Library\Usage\Contracts\PaymentProviderGateway::class);

$paymentMethodId = 'pm_fake_child_confirm';
$fake->registerPaymentMethod(new App\Library\Usage\PaymentMethodResult(
    $paymentMethodId,
    '',
    'card', 'visa', '4242', 12, 2030,
));
$fake->registerCheckoutSessionResult(new App\Library\Usage\CheckoutSessionResult(
    $attempt->provider_session_or_intent_reference,
    'complete',
    'paid',
    null,
    $manager->expectedMinorUnitsFor($attempt),
    $manager->expectedCurrencyCodeFor($attempt),
    $attempt->provider_customer_external_id_snapshot,
    'pi_fake_child_confirm',
    $paymentMethodId,
    'https://fake.stripe.test/receipts/ch_fake_child_confirm',
    'ch_fake_child_confirm',
));

$manager->confirmAttemptFromReturn($attempt);
fwrite(STDOUT, "DONE\n");
PHP;
    }

    private function holdUntilSignalRunnerScript(): string
    {
        return $this->baseRunnerPreamble()."\n".<<<'PHP'

$businessId = (int) $argv[1];
$signalPath = $argv[2];

Illuminate\Support\Facades\DB::transaction(function () use ($businessId, $signalPath) {
    Illuminate\Support\Facades\DB::table('business_usage_wallets')->where('business_id', $businessId)->lockForUpdate()->first();
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    waitForSignal($signalPath);
});
fwrite(STDOUT, "RELEASED\n");
PHP;
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §17 — the
     * Checkout-Session-backed sibling of FundingAttemptExactlyOnceWalletCreditTest's
     * own PaymentIntent-backed idempotency proof: an in-process browser
     * return followed by a redundant webhook for the exact same attempt
     * must credit exactly once, never twice.
     */
    public function test_duplicate_webhook_and_browser_return_credit_a_checkout_backed_top_up_exactly_once(): void
    {
        [$businessId, , $ownerId] = $this->createBusinessWithAttachedInstrument();
        $attemptId = $this->createPendingAttempt($businessId, $ownerId, 4_000_000);

        $attempt = \App\Models\BusinessFundingAttempt::find($attemptId);
        $manager = app(UsageBillingCheckoutManager::class);

        $paymentMethodId = 'pm_fake_dup_confirm';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_dup_confirm',
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_dup_confirm',
            'ch_fake_dup_confirm',
        ));

        // Path 1: the synchronous browser-return confirmation.
        $manager->confirmAttemptFromReturn($attempt);

        // Path 2: a later, redundant webhook for the exact same attempt.
        $event = \App\Models\PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $this->createdPaymentProviderEventIds[] = $event->id;
        $freshAttempt = \App\Models\BusinessFundingAttempt::find($attemptId);
        $manager->confirmAttemptFromWebhook($freshAttempt, $event);

        $ledgerCount = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attemptId)->count();
        $this->assertSame(1, $ledgerCount, 'Exactly one ledger entry must exist for this Checkout-backed funding attempt.');

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame('4000000', (string) $wallet->available_balance_micro);
    }

    public function test_two_concurrent_confirmations_for_the_same_business_each_credit_exactly_once(): void
    {
        [$businessId, , $ownerId] = $this->createBusinessWithAttachedInstrument();
        $attemptOneId = $this->createPendingAttempt($businessId, $ownerId, 2_000_000);
        $attemptTwoId = $this->createPendingAttempt($businessId, $ownerId, 3_000_000);

        $this->runnerPath = sys_get_temp_dir().'/topup_race_confirm_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->confirmRunnerScript());
        $this->signalPath = sys_get_temp_dir().'/topup_race_signal_'.uniqid().'.flag';

        $processOne = new Process([$this->phpBinary(), $this->runnerPath, (string) $attemptOneId, $this->signalPath]);
        $processTwo = new Process([$this->phpBinary(), $this->runnerPath, (string) $attemptTwoId, $this->signalPath]);
        $processOne->setTimeout(15.0);
        $processTwo->setTimeout(15.0);

        $processOne->start();
        $processTwo->start();

        // True handshake, never a fixed sleep: block until BOTH sibling
        // processes have themselves announced "WAITING" before releasing
        // the shared signal file — only then are they guaranteed to race
        // the same wallet row lock simultaneously.
        $bufferOne = '';
        $bufferTwo = '';
        $deadline = microtime(true) + 10.0;

        while ((! str_contains($bufferOne, 'WAITING') || ! str_contains($bufferTwo, 'WAITING')) && microtime(true) < $deadline) {
            $bufferOne .= $processOne->getIncrementalOutput();
            $bufferTwo .= $processTwo->getIncrementalOutput();
            usleep(2000);
        }

        $this->assertTrue(str_contains($bufferOne, 'WAITING') && str_contains($bufferTwo, 'WAITING'), 'Both processes must announce readiness before the race is triggered.');

        file_put_contents($this->signalPath, '1');

        $processOne->wait();
        $processTwo->wait();

        $this->assertTrue($processOne->isSuccessful(), 'Process one did not complete: '.$processOne->getErrorOutput());
        $this->assertTrue($processTwo->isSuccessful(), 'Process two did not complete: '.$processTwo->getErrorOutput());
        $this->assertStringContainsString('DONE', $processOne->getOutput());
        $this->assertStringContainsString('DONE', $processTwo->getOutput());

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro, 'Both concurrent confirmations must be reflected, with neither a lost update nor a double count.');

        $ledgerCount = DB::table('business_usage_ledger_entries')->where('business_id', $businessId)->whereIn('funding_attempt_id', [$attemptOneId, $attemptTwoId])->count();
        $this->assertSame(2, $ledgerCount, 'Exactly one ledger entry per confirmed attempt, never merged or duplicated.');
    }

    public function test_a_held_lock_for_one_business_does_not_block_an_unrelated_businesss_confirmation(): void
    {
        [$businessIdA, , $ownerA] = $this->createBusinessWithAttachedInstrument();
        [$businessIdB, , $ownerB] = $this->createBusinessWithAttachedInstrument();
        $attemptBId = $this->createPendingAttempt($businessIdB, $ownerB, 1_000_000);

        $holdScriptPath = sys_get_temp_dir().'/topup_race_hold_script_'.uniqid().'.php';
        file_put_contents($holdScriptPath, $this->holdUntilSignalRunnerScript());
        $confirmScriptPath = sys_get_temp_dir().'/topup_race_confirm_script_'.uniqid().'.php';
        file_put_contents($confirmScriptPath, $this->confirmRunnerScript());
        $this->signalPath = sys_get_temp_dir().'/topup_race_hold_signal_'.uniqid().'.flag';

        $holder = new Process([$this->phpBinary(), $holdScriptPath, (string) $businessIdA, $this->signalPath]);
        $holder->setTimeout(12.0);

        try {
            $holder->start();

            $locked = false;
            $holder->waitUntil(function ($type, $output) use (&$locked) {
                if (str_contains($output, 'LOCKED')) {
                    $locked = true;

                    return true;
                }

                return false;
            });
            $this->assertTrue($locked, 'Holder process never confirmed its lock on Business A\'s wallet.');

            // Business B's own confirmation proceeds to completion while
            // Business A's unrelated wallet row remains locked — a
            // no-op signal path means it never blocks on the holder's own
            // gate. If B were ever serialized behind A's unrelated lock,
            // this call could never return before the deadline below.
            $noOpSignalPath = sys_get_temp_dir().'/topup_race_noop_signal_'.uniqid().'.flag';
            file_put_contents($noOpSignalPath, '1');
            $other = new Process([$this->phpBinary(), $confirmScriptPath, (string) $attemptBId, $noOpSignalPath]);
            $other->setTimeout(12.0);
            $other->run();
            @unlink($noOpSignalPath);
            @unlink($confirmScriptPath);

            $this->assertTrue($other->isSuccessful(), 'Business B\'s confirmation did not complete independently while Business A\'s unrelated lock was held: '.$other->getErrorOutput());
            $this->assertStringContainsString('DONE', $other->getOutput());

            $walletB = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessIdB);
            $this->assertSame('1000000', (string) $walletB->available_balance_micro);

            file_put_contents($this->signalPath, '1');
            $holder->wait();
            $this->assertStringContainsString('RELEASED', $holder->getOutput());
        } finally {
            if ($holder->isRunning()) {
                $holder->stop();
            }

            @unlink($holdScriptPath);
        }
    }
}
