<?php

namespace Tests\Feature\Usage;

use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Jobs\Usage\SendAutoRechargeDisabledNotification;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §15/§25 item 96 — consecutive_recharge_failures
 * increments on failure, resets on success; an outstanding in-flight
 * auto-recharge attempt prevents a duplicate concurrent one (forced-race).
 *
 * Deliberately does NOT use RefreshDatabase — the forced-race assertion
 * needs a genuinely separate OS process to see already-committed rows,
 * which an open RefreshDatabase transaction would hide entirely,
 * mirroring UsageWalletManagerConcurrencyTest's own established precedent
 * exactly. Every fixture row this file creates is built directly and
 * tracked for manual cleanup in tearDown() (never CreatesBusinessTestData,
 * which assumes RefreshDatabase's own transactional rollback). The runner
 * script is generated at run time into the real OS temp directory (never
 * a repository file) and removed in tearDown.
 */
class AutoRechargeFailedPaymentRetryTest extends TestCase
{
    private array $createdBusinessIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];
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

        if ($this->createdBusinessIds !== []) {
            DB::table('business_funding_attempt_transitions')->whereIn(
                'funding_attempt_id',
                DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->pluck('id')
            )->delete();
            DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_payment_instruments')->whereIn(
                'provider_customer_id',
                DB::table('payment_provider_customers')->whereIn('business_id', $this->createdBusinessIds)
                    ->orWhereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id')
            )->delete();
            DB::table('payment_provider_customers')->whereIn('business_id', $this->createdBusinessIds)
                ->orWhereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('business_payer_assignments')->whereIn('business_id', $this->createdBusinessIds)->delete();
            // Receipt Boundary Correction Contract §3 — a successful
            // AutoRecharge credit now synchronously creates a
            // business_billing_receipts row (this class deliberately does
            // not use RefreshDatabase, so it is a real, committed row);
            // its ledger_entry_id FK must be cleared before the ledger
            // rows below, mirroring ConcurrentTopUpConcurrencyTest's own
            // identical fix.
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
     * Builds a Business with an initialized wallet, workspace-payer
     * assignment, an attached payment instrument, and auto-recharge
     * configured (threshold 2,000,000, amount 3,000,000, no cap) — all
     * through real manager calls (never a hand-rolled shortcut), so this
     * fixture exercises the exact same code paths a RefreshDatabase-based
     * test would, just without relying on transactional rollback.
     *
     * @return array{0: int, 1: int} business_id, owner_user_id
     */
    private function createBusinessWithAutoRechargeConfigured(): array
    {
        $ownerId = $this->createOwnerUserId();

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'AutoRecharge WS '.uniqid(), 'owner_user_id' => $ownerId,
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
            'name' => 'AutoRecharge Biz', 'industry' => 'photo_booth_service', 'country_code' => 'US',
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

        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '3000000', null, $ownerId);

        return [$businessId, $ownerId];
    }

    public function test_a_declined_recharge_increments_the_failure_counter(): void
    {
        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => '1000000']);

        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];
        EvaluateBusinessAutoRecharge::dispatch($businessId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame(1, $wallet->consecutive_recharge_failures);
        $this->assertSame('1000000', (string) $wallet->available_balance_micro);
    }

    /**
     * RFC-005 §19, made authoritative by the Job/Event Dispatch Completion
     * Correction Contract §5 item 1 — a requires_action outcome now
     * increments the failure counter exactly like a Failed outcome,
     * superseding this test's own prior name/assertion.
     */
    public function test_a_requires_action_outcome_increments_the_failure_counter(): void
    {
        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => '1000000']);

        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        EvaluateBusinessAutoRecharge::dispatch($businessId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame(1, $wallet->consecutive_recharge_failures);
    }

    public function test_a_subsequent_successful_recharge_resets_the_failure_counter(): void
    {
        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['available_balance_micro' => '1000000', 'consecutive_recharge_failures' => 2]);

        $this->gateway->paymentIntentOutcomes = ['*' => 'succeeded'];
        EvaluateBusinessAutoRecharge::dispatch($businessId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame(0, $wallet->consecutive_recharge_failures);
        $this->assertSame('4000000', (string) $wallet->available_balance_micro);
    }

    /**
     * Contract §5 items 3-4 — three real, sequential Failed outcomes (each
     * creates its own attempt; 'failed' is not an OUTSTANDING_STATES
     * value, so it never blocks the next one) reach the 2->3 edge, which
     * must disable auto-recharge and dispatch the notification exactly
     * once. Queue::fake() is safe here because EvaluateBusinessAutoRecharge
     * is invoked via a direct handle() call, never ::dispatch(), so the
     * fake only ever observes SendAutoRechargeDisabledNotification.
     */
    public function test_the_third_consecutive_failure_disables_auto_recharge_and_dispatches_the_disabled_notification(): void
    {
        Queue::fake();

        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => '1000000']);
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];

        $walletRepository = app(BusinessUsageWalletRepository::class);
        $attemptRepository = app(BusinessFundingAttemptRepository::class);

        for ($i = 0; $i < 3; $i++) {
            (new EvaluateBusinessAutoRecharge($businessId))->handle($walletRepository, $attemptRepository);
        }

        $wallet = $walletRepository->findByBusinessId($businessId);
        $this->assertSame(3, $wallet->consecutive_recharge_failures);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);
        Queue::assertPushed(SendAutoRechargeDisabledNotification::class, 1);
    }

    /**
     * Contract §5 items 1/3/4 — requires_action now counts toward the same
     * disable threshold. A requires_action attempt is itself an
     * OUTSTANDING_STATES value, so a real attempt left in that state
     * blocks a second automatic evaluation from ever creating a second
     * attempt — a genuine, pre-existing mechanical constraint, not
     * something this correction changes. The failure count is therefore
     * seeded to 2 (mirroring test_a_subsequent_successful_recharge_resets_
     * the_failure_counter's own established seeding convention for
     * threshold-boundary tests), and the real job's widened conditional
     * is exercised once for the exact 2->3 transition under test.
     */
    public function test_the_third_consecutive_requires_action_outcome_also_disables_auto_recharge(): void
    {
        Queue::fake();

        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['available_balance_micro' => '1000000', 'consecutive_recharge_failures' => 2]);
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];

        $walletRepository = app(BusinessUsageWalletRepository::class);
        $attemptRepository = app(BusinessFundingAttemptRepository::class);

        (new EvaluateBusinessAutoRecharge($businessId))->handle($walletRepository, $attemptRepository);

        $wallet = $walletRepository->findByBusinessId($businessId);
        $this->assertSame(3, $wallet->consecutive_recharge_failures);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);
        Queue::assertPushed(SendAutoRechargeDisabledNotification::class, 1);
    }

    /**
     * Contract §5 item 4 — the system-disable path must never reuse
     * configureAutoRecharge(enabled: false, ...) internally, since that
     * method nulls threshold/amount/cap; a system disable preserves them
     * exactly so a human can decide whether to re-enable at the same
     * configuration later.
     */
    public function test_system_disable_preserves_threshold_amount_and_monthly_cap(): void
    {
        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['available_balance_micro' => '1000000', 'consecutive_recharge_failures' => 2]);
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];

        EvaluateBusinessAutoRecharge::dispatch($businessId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);
        $this->assertSame('2000000', (string) $wallet->auto_recharge_threshold_micro);
        $this->assertSame('3000000', (string) $wallet->auto_recharge_amount_micro);
    }

    /**
     * Contract §5 item 5 — the disable-and-notify branch is gated on
     * exactly === 3, not >= 3, defending against any out-of-band caller
     * reached after the wallet is already disabled.
     */
    public function test_a_failure_recorded_while_already_disabled_does_not_redispatch_the_notification(): void
    {
        Queue::fake();

        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['consecutive_recharge_failures' => 3, 'auto_recharge_enabled' => false]);

        app(UsageWalletManager::class)->recordAutoRechargeFailure($businessId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame(4, $wallet->consecutive_recharge_failures);
        Queue::assertNotPushed(SendAutoRechargeDisabledNotification::class);
    }

    /**
     * Contract §5 item 6 — an explicit disabled->enabled transition resets
     * the counter and starts a new failure episode, permitting a fresh
     * disable-and-notify dispatch on a later, genuinely new 2->3 edge.
     */
    public function test_re_enabling_auto_recharge_resets_the_counter_and_permits_a_new_disable_episode(): void
    {
        Queue::fake();

        [$businessId, $ownerId] = $this->createBusinessWithAutoRechargeConfigured();
        $business = Business::find($businessId);
        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['consecutive_recharge_failures' => 3, 'auto_recharge_enabled' => false]);

        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '3000000', null, $ownerId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertSame(0, $wallet->consecutive_recharge_failures);
        $this->assertTrue((bool) $wallet->auto_recharge_enabled);

        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['consecutive_recharge_failures' => 2, 'available_balance_micro' => '1000000']);
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];

        $walletRepository = app(BusinessUsageWalletRepository::class);
        $attemptRepository = app(BusinessFundingAttemptRepository::class);

        // Queue::fake() is already active from the top of this test, so
        // EvaluateBusinessAutoRecharge::dispatch() would be faked, not
        // run — a direct handle() call is required to genuinely exercise
        // the new episode.
        (new EvaluateBusinessAutoRecharge($businessId))->handle($walletRepository, $attemptRepository);

        $wallet = $walletRepository->findByBusinessId($businessId);
        $this->assertSame(3, $wallet->consecutive_recharge_failures);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);
        Queue::assertPushed(SendAutoRechargeDisabledNotification::class, 1);
    }

    /**
     * Contract §5 item 7 — a deliberate owner/administrator disable via
     * configureAutoRecharge(enabled: false, ...) is never the system-
     * disable path and must never dispatch the notification.
     */
    public function test_deliberate_owner_disable_does_not_dispatch_the_system_disable_notification(): void
    {
        Queue::fake();

        [$businessId, $ownerId] = $this->createBusinessWithAutoRechargeConfigured();
        $business = Business::find($businessId);

        app(UsageWalletManager::class)->configureAutoRecharge($business, false, null, null, null, $ownerId);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($businessId);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);
        $this->assertNull($wallet->auto_recharge_threshold_micro);
        Queue::assertNotPushed(SendAutoRechargeDisabledNotification::class);
    }

    // --- Forced-race: concurrent evaluations never spawn two attempts ---

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

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

\$businessId = (int) \$argv[1];
\$signalPath = \$argv[2];

app()->instance(
    \App\Library\Usage\Contracts\PaymentProviderGateway::class,
    new \App\Library\Usage\FakePaymentProviderGateway()
);

// Deterministic causal barrier: this process announces readiness on
// stdout (the parent's Process::waitUntil() blocks on that line for
// BOTH sibling processes before writing the shared signal file), then
// blocks on that file — never a fragile wall-clock ceiling. Fixed-sleep
// releases are insufficient here: Laravel's own subprocess bootstrap
// time is not bounded tightly enough to guarantee both siblings have
// reached this point before a sleep-based release fires, which would
// silently degrade the race back into an unsynchronized one. The
// bounded deadline below is a deadlock/safety net only.
fwrite(STDOUT, "WAITING\\n");
fflush(STDOUT);
\$deadline = microtime(true) + 10.0;
while (! file_exists(\$signalPath)) {
    if (microtime(true) >= \$deadline) {
        fwrite(STDOUT, "TIMEOUT\\n");
        exit(1);
    }
    usleep(5000);
}

\$job = new \App\Jobs\Usage\EvaluateBusinessAutoRecharge(\$businessId);
\$job->handle(
    app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class),
    app(\App\Repositories\Contracts\BusinessFundingAttemptRepository::class)
);
fwrite(STDOUT, "DONE\\n");
PHP;
    }

    public function test_two_concurrent_evaluations_for_the_same_business_never_create_two_attempts(): void
    {
        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => '1000000']);

        $this->runnerPath = sys_get_temp_dir().'/auto_recharge_race_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
        $this->signalPath = sys_get_temp_dir().'/auto_recharge_race_signal_'.uniqid().'.flag';

        $processA = new Process([$this->phpBinary(), $this->runnerPath, (string) $businessId, $this->signalPath]);
        $processB = new Process([$this->phpBinary(), $this->runnerPath, (string) $businessId, $this->signalPath]);
        $processA->setTimeout(15.0);
        $processB->setTimeout(15.0);

        $processA->start();
        $processB->start();

        // True handshake, never a fixed sleep: block until BOTH sibling
        // processes have themselves announced "WAITING" on their own
        // stdout before releasing the shared signal file — only then are
        // they guaranteed to race the same wallet row lock simultaneously.
        $bufferA = '';
        $bufferB = '';
        $deadline = microtime(true) + 10.0;

        while ((! str_contains($bufferA, 'WAITING') || ! str_contains($bufferB, 'WAITING')) && microtime(true) < $deadline) {
            $bufferA .= $processA->getIncrementalOutput();
            $bufferB .= $processB->getIncrementalOutput();
            usleep(2000);
        }

        $this->assertTrue(str_contains($bufferA, 'WAITING') && str_contains($bufferB, 'WAITING'), 'Both processes must announce readiness before the race is triggered.');

        file_put_contents($this->signalPath, '1');

        $processA->wait();
        $processB->wait();

        $this->assertTrue($processA->isSuccessful(), 'Process A did not complete: '.$processA->getErrorOutput());
        $this->assertTrue($processB->isSuccessful(), 'Process B did not complete: '.$processB->getErrorOutput());
        $this->assertStringContainsString('DONE', $processA->getOutput());
        $this->assertStringContainsString('DONE', $processB->getOutput());

        $attemptCount = DB::table('business_funding_attempts')
            ->where('business_id', $businessId)
            ->where('purpose', 'auto_recharge')
            ->count();
        $this->assertSame(1, $attemptCount, 'Exactly one auto-recharge attempt must exist after two concurrent evaluations.');
    }
}
