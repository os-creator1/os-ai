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
}
