<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §11 — the ledger's own correlation_key UNIQUE
 * constraint as the sole idempotency/concurrency-safety mechanism for
 * refund/dispute outcomes, proven under a genuine OS-level race between
 * two independent, real processes — the established subprocess/causal-
 * barrier convention (RefundablePaidAvailableAccountingTest's own).
 */
class ProviderRefundDisputeConcurrencyTest extends TestCase
{
    use CreatesBusinessTestData;

    private array $createdBusinessIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];
    private ?int $createdCurrencyId = null;
    private ?string $runnerPath = null;
    private ?string $signalPath = null;
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

        if ($this->createdBusinessIds !== []) {
            DB::table('payment_provider_events')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_wallet_billing_status_transitions')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_billing_receipts')->whereIn('ledger_entry_id', function ($query) {
                $query->select('id')->from('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds);
            })->delete();
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->update(['reversed_entry_id' => null]);
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_funding_attempt_transitions')->whereIn('funding_attempt_id', function ($query) {
                $query->select('id')->from('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds);
            })->delete();
            DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_reservations')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('payment_provider_customers')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_payer_assignments')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_payer_transitions')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('payment_provider_customers')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
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

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $this->createdWorkspaceIds[] = $workspace->id;
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->createdUserIds[] = $admin->id;
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    private function attemptWithVerifiedSuccess(int $amountMicro, string $chargeId): BusinessFundingAttempt
    {
        $customer = $this->createCustomer();
        $this->createdUserIds[] = $customer->user_id;
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        $this->createdBusinessIds[] = $business->id;
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customer->user_id, $amountMicro);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult($paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_fake_'.uniqid(), $paymentMethodId,
            'https://fake.stripe.test/receipts/'.$chargeId, $chargeId,
        ));
        $manager->confirmAttemptFromReturn($attempt);

        return app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
    }

    private function wallet(int $businessId): object
    {
        return DB::table('business_usage_wallets')->where('business_id', $businessId)->first();
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    /**
     * Each child process independently creates and processes its own
     * PaymentProviderEvent row — sharing no in-memory state with the
     * parent or with the sibling process — for the identical charge/
     * dispute reference and cumulative/balance-transaction figure the
     * parent already committed the funding attempt for.
     */
    private function runnerScript(): string
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
\$app = require '{$escapedBootstrap}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

app()->instance(
    App\Library\Usage\Contracts\PaymentProviderGateway::class,
    new App\Library\Usage\FakePaymentProviderGateway()
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

\$eventType = \$argv[1];
\$objectJson = \$argv[2];
\$signalPath = \$argv[3];
\$object = json_decode(\$objectJson, true);

\$event = App\Models\PaymentProviderEvent::create([
    'provider' => 'stripe',
    'provider_event_id' => 'evt_race_'.uniqid(),
    'event_type' => \$eventType,
    'provider_object_id' => (string) (\$object['id'] ?? ''),
    'payload_encrypted' => json_encode(['data' => ['object' => \$object]]),
    'payload_hash' => hash('sha256', uniqid()),
    'state' => 'received',
    'attempts' => 0,
    'received_at' => now(),
]);

fwrite(STDOUT, "WAITING\\n");
fflush(STDOUT);
waitForSignal(\$signalPath);

app()->call([new App\Jobs\Usage\ProcessPaymentProviderEvent(\$event->id), 'handle']);
fwrite(STDOUT, "DONE\\n");
PHP;
    }

    /**
     * @return array{0: Process, 1: Process}
     */
    private function raceTwoEvents(string $eventType, array $object): array
    {
        $this->runnerPath = sys_get_temp_dir().'/refund_dispute_race_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
        $this->signalPath = sys_get_temp_dir().'/refund_dispute_race_signal_'.uniqid().'.flag';

        $objectJson = json_encode($object);
        $processOne = new Process([$this->phpBinary(), $this->runnerPath, $eventType, $objectJson, $this->signalPath]);
        $processTwo = new Process([$this->phpBinary(), $this->runnerPath, $eventType, $objectJson, $this->signalPath]);
        $processOne->setTimeout(15.0);
        $processTwo->setTimeout(15.0);

        $processOne->start();
        $processTwo->start();

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

        return [$processOne, $processTwo];
    }

    public function test_two_different_provider_event_ids_reporting_the_same_cumulative_refund_amount_debit_the_wallet_exactly_once(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess(5_000_000, 'ch_fake_race_refund');

        $this->raceTwoEvents('charge.refunded', [
            'id' => 'ch_fake_race_refund', 'payment_intent' => null, 'amount_refunded' => 200, 'currency' => 'usd',
        ]);

        $this->assertSame(3_000_000, (int) $this->wallet($attempt->business_id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'refund')->count());
    }

    public function test_two_different_provider_event_ids_reporting_the_same_balance_transaction_apply_the_dispute_chargeback_exactly_once(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess(5_000_000, 'ch_fake_race_dispute');

        $this->raceTwoEvents('charge.dispute.funds_withdrawn', [
            'id' => 'dp_fake_race', 'charge' => 'ch_fake_race_dispute', 'payment_intent' => null, 'currency' => 'usd',
            'status' => 'lost', 'balance_transactions' => [
                ['id' => 'txn_race_withdraw', 'amount' => -200, 'currency' => 'usd', 'net' => -200, 'type' => 'adjustment'],
            ],
        ]);

        $this->assertSame(3_000_000, (int) $this->wallet($attempt->business_id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->count());
    }

    public function test_two_different_provider_event_ids_reporting_the_same_policy_excess_refund_apply_it_exactly_once_with_no_duplicate_suspension(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess(1_000_000, 'ch_fake_race_policyexcess');
        DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->update([
            'available_balance_micro' => 0, 'refundable_paid_available_micro' => 0,
        ]);

        $this->raceTwoEvents('charge.refunded', [
            'id' => 'ch_fake_race_policyexcess', 'payment_intent' => null, 'amount_refunded' => 100, 'currency' => 'usd',
        ]);

        $wallet = $this->wallet($attempt->business_id);
        $this->assertSame('suspended', $wallet->billing_status);
        $this->assertSame(1, DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->count());
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'refund')->count());
    }

    /**
     * RFC-005 Remediation #6 §17, third exceptional post-merge
     * implementation correction — each child process independently binds
     * a decorator over BusinessUsageWalletRepository before resolving
     * ProcessPaymentProviderEvent, so the FIRST findForUpdateByBusinessId()
     * call inside applyRefundOutcome() — the corrected implementation's own
     * sole serialization point, reached before the refund aggregate is
     * ever read — blocks on a per-child ready signal until the parent
     * releases both children together. The existing pre-job "WAITING"
     * start gate alone is not sufficient proof: it cannot force both
     * children through the old, broken implementation's own pre-lock
     * aggregate read before either commits, so a test relying on it alone
     * could pass against the defective implementation it exists to catch.
     */
    private function differentCumulativeRunnerScript(): string
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
\$app = require '{$escapedBootstrap}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

app()->instance(
    App\Library\Usage\Contracts\PaymentProviderGateway::class,
    new App\Library\Usage\FakePaymentProviderGateway()
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

\$cumulativeMinorUnits = (int) \$argv[1];
\$chargeId = \$argv[2];
\$readySignalPath = \$argv[3];
\$releaseSignalPath = \$argv[4];

\$realWalletRepository = app(App\Repositories\Contracts\BusinessUsageWalletRepository::class);

\$decorator = new class(\$realWalletRepository, \$readySignalPath, \$releaseSignalPath) implements App\Repositories\Contracts\BusinessUsageWalletRepository {
    private bool \$barrierPassed = false;

    public function __construct(
        private readonly App\Repositories\Contracts\BusinessUsageWalletRepository \$real,
        private readonly string \$readySignalPath,
        private readonly string \$releaseSignalPath,
    ) {
    }

    public function findForUpdateByBusinessId(int \$businessId): ?App\Models\BusinessUsageWallet
    {
        if (! \$this->barrierPassed) {
            \$this->barrierPassed = true;
            file_put_contents(\$this->readySignalPath, '1');
            waitForSignal(\$this->releaseSignalPath);
        }

        return \$this->real->findForUpdateByBusinessId(\$businessId);
    }

    public function findByBusinessId(int \$businessId): ?App\Models\BusinessUsageWallet
    {
        return \$this->real->findByBusinessId(\$businessId);
    }

    public function create(array \$attributes): App\Models\BusinessUsageWallet
    {
        return \$this->real->create(\$attributes);
    }

    public function update(App\Models\BusinessUsageWallet \$wallet, array \$attributes): App\Models\BusinessUsageWallet
    {
        return \$this->real->update(\$wallet, \$attributes);
    }

    public function query()
    {
        return \$this->real->query();
    }

    public function search(\$query, \$callback = null)
    {
        return \$this->real->search(\$query, \$callback);
    }

    public function select(array \$columns = ['*'])
    {
        return \$this->real->select(\$columns);
    }

    public function make(array \$attributes = [])
    {
        return \$this->real->make(\$attributes);
    }
};

app()->instance(App\Repositories\Contracts\BusinessUsageWalletRepository::class, \$decorator);

\$event = App\Models\PaymentProviderEvent::create([
    'provider' => 'stripe',
    'provider_event_id' => 'evt_diffcum_'.uniqid(),
    'event_type' => 'charge.refunded',
    'provider_object_id' => \$chargeId,
    'payload_encrypted' => json_encode(['data' => ['object' => [
        'id' => \$chargeId, 'payment_intent' => null, 'amount_refunded' => \$cumulativeMinorUnits, 'currency' => 'usd',
    ]]]),
    'payload_hash' => hash('sha256', uniqid()),
    'state' => 'received',
    'attempts' => 0,
    'received_at' => now(),
]);

app()->call([new App\Jobs\Usage\ProcessPaymentProviderEvent(\$event->id), 'handle']);

\$fresh = App\Models\PaymentProviderEvent::find(\$event->id);
fwrite(STDOUT, "RESULT:{\$fresh->id}\\n");
PHP;
    }

    public function test_two_different_provider_event_ids_reporting_different_cumulative_refund_amounts_never_exceed_the_true_provider_cumulative(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess(1_000_000, 'ch_fake_race_diffcum');
        // attemptWithVerifiedSuccess() already leaves both available_balance_micro
        // and refundable_paid_available_micro at exactly 1,000,000 — at least
        // "100 units" of each, and expected_amount_micro is exactly 1,000,000
        // ("100 units") — the fixture the contract locks.

        $runnerPath = sys_get_temp_dir().'/refund_dispute_diffcum_runner_'.uniqid().'.php';
        file_put_contents($runnerPath, $this->differentCumulativeRunnerScript());
        $readySignalSixty = sys_get_temp_dir().'/refund_dispute_diffcum_ready_60_'.uniqid().'.flag';
        $readySignalHundred = sys_get_temp_dir().'/refund_dispute_diffcum_ready_100_'.uniqid().'.flag';
        $releaseSignal = sys_get_temp_dir().'/refund_dispute_diffcum_release_'.uniqid().'.flag';

        // Minor units: 60 and 100 (10,000 micro per minor unit for USD),
        // reported against the same charge reference, each event created by
        // its own child process with its own distinct provider_event_id.
        $processSixty = new Process([$this->phpBinary(), $runnerPath, '60', 'ch_fake_race_diffcum', $readySignalSixty, $releaseSignal]);
        $processHundred = new Process([$this->phpBinary(), $runnerPath, '100', 'ch_fake_race_diffcum', $readySignalHundred, $releaseSignal]);
        $processSixty->setTimeout(15.0);
        $processHundred->setTimeout(15.0);

        $processSixty->start();
        $processHundred->start();

        $deadline = microtime(true) + 10.0;

        while ((! file_exists($readySignalSixty) || ! file_exists($readySignalHundred)) && microtime(true) < $deadline) {
            usleep(2000);
        }

        $this->assertTrue(
            file_exists($readySignalSixty) && file_exists($readySignalHundred),
            'Both child processes must reach the corrected implementation\'s own first wallet-lock call — its sole serialization point — before the race is triggered.',
        );

        file_put_contents($releaseSignal, '1');

        $processSixty->wait();
        $processHundred->wait();

        $this->assertTrue($processSixty->isSuccessful(), 'Cumulative-60 process did not complete: '.$processSixty->getErrorOutput());
        $this->assertTrue($processHundred->isSuccessful(), 'Cumulative-100 process did not complete: '.$processHundred->getErrorOutput());

        @unlink($runnerPath);
        @unlink($readySignalSixty);
        @unlink($readySignalHundred);
        @unlink($releaseSignal);

        preg_match('/RESULT:(\d+)/', $processSixty->getOutput(), $matchesSixty);
        preg_match('/RESULT:(\d+)/', $processHundred->getOutput(), $matchesHundred);
        $this->assertNotEmpty($matchesSixty, 'Cumulative-60 process produced no RESULT line: '.$processSixty->getOutput());
        $this->assertNotEmpty($matchesHundred, 'Cumulative-100 process produced no RESULT line: '.$processHundred->getOutput());

        $eventSixty = PaymentProviderEvent::find((int) $matchesSixty[1]);
        $eventHundred = PaymentProviderEvent::find((int) $matchesHundred[1]);

        // Order-independent invariants, true regardless of which process
        // actually won the wallet lock first.
        $refundRows = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'refund')->get();
        $totalGross = 0;
        $totalDebit = 0;

        foreach ($refundRows as $row) {
            $totalGross += (int) $row->gross_amount_micro;
            $totalDebit += abs((int) $row->available_delta_micro);
        }

        $this->assertSame(1_000_000, $totalGross, 'Total recorded Refund gross must equal exactly the provider\'s true cumulative of 100 units, never 160.');
        $this->assertSame(1_000_000, $totalDebit, 'Total wallet debit must equal exactly 100 units.');

        $wallet = $this->wallet($attempt->business_id);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro, 'refundable_paid_available_micro must fall by exactly 100 units from its starting 1,000,000.');
        $this->assertNotSame('suspended', $wallet->billing_status, 'No provider-refund-mismatch suspension may occur when 100 units is genuinely within the wallet\'s own starting balance.');
        $this->assertSame(0, DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->count());

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Refunded, $freshAttempt->state, 'The funding attempt\'s own final persisted state must be Refunded in either lock order.');

        // Both permitted lock-order outcomes, asserted per whichever the
        // real race actually produced.
        if ((int) $eventSixty->normalized_outcome_delta_micro === 600_000) {
            // Cumulative 60 committed first.
            $this->assertSame('succeeded', $eventSixty->normalized_status, 'Cumulative 60, winning first, must record its own contemporaneous Succeeded status.');
            $this->assertSame(400_000, (int) $eventHundred->normalized_outcome_delta_micro, 'Cumulative 100, arriving second, must apply only the remaining delta of 40 units.');
            $this->assertSame('refunded', $eventHundred->normalized_status);
        } else {
            // Cumulative 100 committed first; cumulative 60 becomes a
            // zero-delta no-op — the exact case proving the stale
            // $attempt->state defect is fixed.
            $this->assertSame(1_000_000, (int) $eventHundred->normalized_outcome_delta_micro, 'Cumulative 100, winning first, must apply the complete delta of 100 units.');
            $this->assertSame('refunded', $eventHundred->normalized_status);
            $this->assertSame(0, (int) $eventSixty->normalized_outcome_delta_micro, 'Cumulative 60, arriving second, must compute a zero delta — already fully recorded.');
            $this->assertSame(
                'refunded',
                $eventSixty->normalized_status,
                'Cumulative 60\'s own zero-delta no-op must report the authoritative persisted Refunded state, never a stale pre-lock Succeeded snapshot.',
            );
        }
    }
}
