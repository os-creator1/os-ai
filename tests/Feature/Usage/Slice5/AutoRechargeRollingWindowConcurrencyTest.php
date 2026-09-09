<?php

namespace Tests\Feature\Usage\Slice5;

use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §5.1, §7.2 and §13.6
 * test 42: two genuinely concurrent evaluations (two OS processes racing
 * the same wallet row lock, released by one shared signal) cannot both
 * claim the final rolling-window slot. With one automatic top-up already
 * in the window, exactly one of the two racers creates the second attempt;
 * the other is refused under the same lock either by the outstanding-
 * attempt rule (the winner's claim still pending) or by the frequency
 * limit (the winner already settled) — never a third attempt, never a
 * second provider charge.
 *
 * Deliberately does NOT use RefreshDatabase — the sibling processes must
 * see committed rows — and cleans up its own rows in tearDown(), mirroring
 * AutoRechargeFailedPaymentRetryTest.
 */
class AutoRechargeRollingWindowConcurrencyTest extends TestCase
{
    private FakePaymentProviderGateway $gateway;

    private ?string $runnerPath = null;

    private ?string $signalPath = null;

    /** @var list<int> */
    private array $createdBusinessIds = [];

    /** @var list<int> */
    private array $createdWorkspaceIds = [];

    /** @var list<int> */
    private array $createdUserIds = [];

    private ?int $createdCurrencyId = null;

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
        foreach ([$this->runnerPath, $this->signalPath] as $path) {
            if ($path !== null && file_exists($path)) {
                @unlink($path);
            }
        }

        if ($this->createdBusinessIds !== []) {
            DB::table('business_funding_attempt_transitions')->whereIn(
                'funding_attempt_id',
                DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->pluck('id'),
            )->delete();
            DB::table('business_funding_attempts')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_payment_instruments')->whereIn(
                'provider_customer_id',
                DB::table('payment_provider_customers')->whereIn('business_id', $this->createdBusinessIds)
                    ->orWhereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id'),
            )->delete();
            DB::table('payment_provider_customers')->whereIn('business_id', $this->createdBusinessIds)
                ->orWhereIn('workspace_id', $this->createdWorkspaceIds)->delete();
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
            'email' => 'owner' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;
        DB::table('customers')->insert(['user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array{0: int, 1: int} business id, owner id */
    private function createBusinessWithAutoRechargeConfigured(): array
    {
        $ownerId = $this->createOwnerUserId();

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'RollingWindow WS ' . uniqid(), 'owner_user_id' => $ownerId,
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
            'name' => 'RollingWindow Biz', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'draft',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        $business = Business::find($businessId);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessId);
        DB::table('business_payer_assignments')->insert([
            'business_id' => $businessId, 'payer_type' => 'workspace', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $ownerId);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId($workspaceId);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_' . substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $ownerId, $setupIntent->providerSetupIntentId);

        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '20000000', '5000000', (string) UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO, $ownerId);

        return [$businessId, $ownerId];
    }

    /**
     * The explicit database handoff every child process receives.
     *
     * Mirrors ConversationsConcurrencyTest::childEnvironment() exactly.
     * The parent resolves and VALIDATES the disposable database it is
     * itself connected to — TestDatabaseSafety::activeTestDatabase()
     * throws unless it is the canonical test database or a clearly
     * derived isolated sibling — and hands that exact name down under
     * both keys: DB_DATABASE so the child connects to it, and
     * EXPECTED_TEST_DATABASE so the child can prove it did.
     *
     * Relying on ambient inheritance alone would give the child the right
     * value but no proof of it; passing it explicitly means the child can
     * distinguish "the parent authorized this database" from "something
     * in my environment happened to point here".
     *
     * Symfony merges this into the inherited environment, so the child
     * still receives everything else it needs.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function runnerScript(): string
    {
        $escapedVendor = str_replace('\\', '\\\\', base_path('vendor/autoload.php'));
        $escapedBootstrap = str_replace('\\', '\\\\', base_path('bootstrap/app.php'));

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

// Fail-closed database guard, before the first database write.
//
// EXPECTED_TEST_DATABASE is MANDATORY. A missing value is not "no
// expectation" — it means the parent handoff did not happen, so this
// child cannot know which database it is authorized to write to and
// must refuse rather than silently fall back to the canonical name.
//
// Tests\Support\TestDatabaseSafety is the single authority on which
// names are permitted; it rejects empty, malformed, unsafe and
// production-looking values, and never accepts a name merely because it
// contains "test".
const WRONG_DATABASE_EXIT_CODE = 3;

\$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if (\$expectedDatabase === false || \$expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    \Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase(\$expectedDatabase);
} catch (\RuntimeException \$e) {
    fwrite(STDERR, 'Refusing to run: ' . \$e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

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

    public function test_two_concurrent_evaluations_cannot_both_claim_the_final_rolling_window_slot(): void
    {
        [$businessId] = $this->createBusinessWithAutoRechargeConfigured();

        // One automatic top-up already inside the window: exactly one slot remains.
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => '1000000']);
        EvaluateBusinessAutoRecharge::dispatch($businessId);
        $this->assertSame(1, DB::table('business_funding_attempts')->where('business_id', $businessId)->where('purpose', 'auto_recharge')->where('state', 'succeeded')->count());
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => '1000000']);

        $this->runnerPath = sys_get_temp_dir() . '/auto_recharge_window_race_runner_' . uniqid() . '.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
        $this->signalPath = sys_get_temp_dir() . '/auto_recharge_window_race_signal_' . uniqid() . '.flag';

        $processA = new Process([$this->phpBinary(), $this->runnerPath, (string) $businessId, $this->signalPath], null, $this->childEnvironment());
        $processB = new Process([$this->phpBinary(), $this->runnerPath, (string) $businessId, $this->signalPath], null, $this->childEnvironment());
        $processA->setTimeout(20.0);
        $processB->setTimeout(20.0);
        $processA->start();
        $processB->start();

        $bufferA = '';
        $bufferB = '';
        $deadline = microtime(true) + 15.0;

        while ((! str_contains($bufferA, 'WAITING') || ! str_contains($bufferB, 'WAITING')) && microtime(true) < $deadline) {
            $bufferA .= $processA->getIncrementalOutput();
            $bufferB .= $processB->getIncrementalOutput();
            usleep(2000);
        }

        $this->assertTrue(str_contains($bufferA, 'WAITING') && str_contains($bufferB, 'WAITING'), 'Both processes must announce readiness before the race is released.');

        file_put_contents($this->signalPath, '1');
        $processA->wait();
        $processB->wait();

        $this->assertTrue($processA->isSuccessful(), 'Process A did not complete: ' . $processA->getErrorOutput());
        $this->assertTrue($processB->isSuccessful(), 'Process B did not complete: ' . $processB->getErrorOutput());
        $this->assertStringContainsString('DONE', $processA->getOutput());
        $this->assertStringContainsString('DONE', $processB->getOutput());

        $attempts = DB::table('business_funding_attempts')->where('business_id', $businessId)->where('purpose', 'auto_recharge');
        $this->assertSame(2, $attempts->count(), 'Exactly one racer may claim the final slot: two attempts in total, never three.');
        $this->assertSame(2, (clone $attempts)->where('state', 'succeeded')->count());
        $this->assertSame('6000000', (string) DB::table('business_usage_wallets')->where('business_id', $businessId)->value('available_balance_micro'), 'Exactly one racer charged.');
        $this->assertSame(0, (clone $attempts)->where('state', 'failed')->count());
    }
}
