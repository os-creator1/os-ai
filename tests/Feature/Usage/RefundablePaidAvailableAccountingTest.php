<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §6/§21/§23 — the refundable-paid-available
 * counter's own exact, deterministic, paid-first allocation across every
 * mutation site, proven directly against UsageWalletManager, isolated
 * from the full webhook stack. Every method number matches the
 * corresponding numbered requirement from Blocker 1.
 */
class RefundablePaidAvailableAccountingTest extends TestCase
{
    use CreatesBusinessTestData;

    private array $createdBusinessIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];
    private ?int $createdCurrencyId = null;
    private ?string $runnerPath = null;
    private ?string $signalPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Currency::query()->where('code', 'USD')->count() === 0) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());

        // These tests exercise UsageWalletManager directly against
        // synthetic funding_attempt_id values with no real
        // business_funding_attempts row backing them — creditFromFunding()
        // unconditionally dispatches SendReceiptNotification, which would
        // otherwise throw against a nonexistent attempt under
        // QUEUE_CONNECTION=sync.
        Queue::fake();
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
            DB::table('business_usage_wallet_billing_status_transitions')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->update(['reversed_entry_id' => null]);
            DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_reservations')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        DB::table('usage_meter_transitions')->where('meter_key', 'like', 'rpa_test_%')->delete();
        DB::table('usage_meters')->where('meter_key', 'like', 'rpa_test_%')->update(['active_rate_id' => null]);
        DB::table('business_usage_rate_activations')->where('meter_key', 'like', 'rpa_test_%')->delete();
        DB::table('business_usage_rates')->where('meter_key', 'like', 'rpa_test_%')->delete();
        DB::table('usage_meters')->where('meter_key', 'like', 'rpa_test_%')->delete();

        if ($this->createdCurrencyId !== null) {
            DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
        }

        parent::tearDown();
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();
        $this->createdUserIds[] = $customer->user_id;
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->createdBusinessIds[] = $business->id;
        $this->createdWorkspaceIds[] = $business->workspace_id;
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        return $business;
    }

    private function wallet(int $businessId): object
    {
        return DB::table('business_usage_wallets')->where('business_id', $businessId)->first();
    }

    private function creditPaid(int $businessId, int $amountMicro, int $fundingAttemptId): void
    {
        app(UsageWalletManager::class)->creditFromFunding(
            $businessId,
            UsageLedgerEntryType::PaidTopUp,
            $amountMicro,
            $fundingAttemptId,
            'rpa-credit:'.$fundingAttemptId.':'.uniqid(),
        );
    }

    private function activateRate(int $businessId, string $featureKey, string $retailRateMicro): void
    {
        $actorId = User::create([
            'first_name' => 'Test', 'last_name' => 'Actor', 'email' => 'actor'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
        $this->createdUserIds[] = $actorId;
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'RefundablePaidAvailableAccountingTest fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate($featureKey, $retailRateMicro, '500000', 'per unit', $currencyId, $actorId, 'Test rate activation.');
        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Test metering activation.');
    }

    public function test_paid_100_consumed_100_then_granted_promotional_credit_100_leaves_zero_refundable_headroom(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_1', '1000000');
        $this->creditPaid($business->id, 100, 1001);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_1', (string) Str::uuid(), '0.0001');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '0.0001');

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro, 'Fixture assumption: fully consumed.');

        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 100, User::query()->where('is_admin', true)->value('id') ?? $this->makeAdmin(), 'Promo.', (string) Str::uuid());

        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro, 'Promotional credit must never inflate refundable-paid headroom.');
    }

    public function test_paid_100_consumed_100_then_granted_manual_credit_100_leaves_zero_refundable_headroom(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_2', '1000000');
        $this->creditPaid($business->id, 100, 1002);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_2', (string) Str::uuid(), '0.0001');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '0.0001');

        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::ManualCredit, 100, $this->makeAdmin(), 'Manual.', (string) Str::uuid());

        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro, 'Manual credit must never inflate refundable-paid headroom.');
    }

    public function test_a_later_unrelated_paid_top_up_never_lets_the_system_refund_more_than_the_globally_tracked_unconsumed_paid_amount(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_3', '1000000');
        $this->creditPaid($business->id, 100, 1003);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_3', (string) Str::uuid(), '0.0001');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '0.0001');

        $this->creditPaid($business->id, 100, 1004);

        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->refundable_paid_available_micro, 'Only the second, still-unconsumed paid credit is refundable.');

        // A refund against the FIRST (already-consumed) attempt is capped
        // at the globally tracked unconsumed amount (100), never the
        // original attempt's own full 100 plus the second credit's own
        // 100 (200).
        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund($business->id, true, 1003, 100, '100', 'ch_test');
        $this->assertSame(-100, (int) $ledgerEntry->available_delta_micro);

        $walletAfter = $this->wallet($business->id);
        $this->assertSame(0, (int) $walletAfter->refundable_paid_available_micro);
    }

    public function test_promotional_or_manual_credit_alone_can_never_be_refunded_for_cash(): void
    {
        $business = $this->business();

        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 100, $this->makeAdmin(), 'Promo.', (string) Str::uuid());

        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro);
    }

    public function test_reserve_removes_the_exact_paid_attributable_amount_from_refundability(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_5', '10000000');
        $this->creditPaid($business->id, 100, 1005);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_5', (string) Str::uuid(), '0.000006');

        $wallet = $this->wallet($business->id);
        $reservationRow = DB::table('business_usage_reservations')->find($reservation->reservationId);

        $this->assertSame((int) $reservationRow->reserved_amount_micro, (int) $reservationRow->paid_attributable_amount_micro);
        $this->assertSame(100 - (int) $reservationRow->reserved_amount_micro, (int) $wallet->refundable_paid_available_micro);
    }

    public function test_release_restores_the_exact_paid_attributable_amount(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_6', '10000000');
        $this->creditPaid($business->id, 100, 1006);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_6', (string) Str::uuid(), '0.000006');
        app(BusinessUsageReservationRepository::class);
        app(UsageWalletManager::class)->release($reservation->reservationId);

        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(100, (int) $wallet->available_balance_micro);
    }

    public function test_a_partial_commit_restores_only_the_unused_paid_allocation(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_7', '10000000');
        $this->creditPaid($business->id, 100, 1007);

        // reserve 6 units => 60 micro reserved; commit 4 units => 40 micro final.
        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_7', (string) Str::uuid(), '0.000006');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '0.000004');

        $wallet = $this->wallet($business->id);
        // Consumed 40 (of the 60 paid-attributable), unused 20 restored: 40 (after reserve) + 20 = 60.
        $this->assertSame(60, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(60, (int) $wallet->available_balance_micro);
    }

    public function test_overage_consumes_refundable_paid_available_under_the_same_allocation_rule(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_8', '10000000');
        $this->creditPaid($business->id, 100, 1008);

        // reserve 6 units => 60 micro reserved; commit 9 units => 90 micro final (30 overage).
        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_8', (string) Str::uuid(), '0.000006');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '0.000009');

        $wallet = $this->wallet($business->id);
        // After reserve: refundable_paid=40, available=40. Overage 30 drawn
        // from available (40>=30) and from refundable_paid (40>=30): both -> 10.
        $this->assertSame(10, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(10, (int) $wallet->available_balance_micro);
    }

    public function test_refund_decrements_refundable_paid_available_and_never_creates_debt(): void
    {
        $business = $this->business();
        $this->creditPaid($business->id, 100, 1009);

        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund($business->id, true, 1009, 100, '100', 'ch_test');

        $this->assertSame(0, (int) $ledgerEntry->debt_delta_micro);
        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(0, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
    }

    public function test_dispute_withdrawal_and_reinstatement_update_refundable_paid_provenance_without_making_consumed_credit_refundable(): void
    {
        $business = $this->business();
        $this->creditPaid($business->id, 100, 1010);

        $chargebackEntry = app(UsageWalletManager::class)->applyDisputeWithdrawal($business->id, true, 1010, 100, 'dp_test', 'txn_withdraw');
        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(0, (int) $wallet->available_balance_micro);

        // An intervening, unrelated ManualCredit must never influence the
        // reinstatement's own refundable-paid restoration bound.
        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::ManualCredit, 50, $this->makeAdmin(), 'Manual.', (string) Str::uuid());

        $originalPaidPortionRemoved = abs((int) $chargebackEntry->refundable_paid_delta_micro);
        $reversalEntry = app(UsageWalletManager::class)->reinstateDisputedFunds($business->id, true, 1010, 100, $originalPaidPortionRemoved, (int) $chargebackEntry->id, 'dp_test', 'txn_reinstate');

        $this->assertSame(100, (int) $reversalEntry->refundable_paid_delta_micro);
        $wallet = $this->wallet($business->id);
        $this->assertSame(150, (int) $wallet->available_balance_micro, '50 manual + 100 reinstated.');
        $this->assertSame(100, (int) $wallet->refundable_paid_available_micro, 'Reinstatement restores exactly what the original chargeback removed, unaffected by the intervening manual credit.');
    }

    public function test_direct_deliverable_outcomes_never_touch_refundable_paid_available(): void
    {
        $business = $this->business();
        $this->creditPaid($business->id, 100, 1011);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['refundable_paid_available_micro' => 100, 'available_balance_micro' => 100]);

        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund($business->id, false, 1011, 100, '100', 'ch_test');

        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro);
        $this->assertSame(0, (int) $ledgerEntry->refundable_paid_delta_micro);
        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->refundable_paid_available_micro, 'A zero-delta outcome must never touch the counter.');
        $this->assertSame(100, (int) $wallet->available_balance_micro);
    }

    public function test_historically_ambiguous_balances_fail_closed_with_zero_backfilled_refundable_paid_available(): void
    {
        $business = $this->business();

        // Simulate a pre-migration wallet whose available balance carries
        // no known paid-vs-non-paid provenance: refundable_paid_available_micro
        // remains at its safe, backfilled default of 0 even though
        // available_balance_micro is genuinely positive.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 100]);

        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund($business->id, true, 1012, 100, '100', 'ch_test');

        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro, 'Zero refundable-paid headroom must deny any cash refund despite a positive available balance.');
        $wallet = $this->wallet($business->id);
        $this->assertSame(100, (int) $wallet->available_balance_micro, 'The available balance itself is untouched by a fully-denied refund.');
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function refundRunnerScript(): string
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

\$businessId = (int) \$argv[1];
\$fundingAttemptId = (int) \$argv[2];
\$signalPath = \$argv[3];

fwrite(STDOUT, "WAITING\\n");
fflush(STDOUT);
waitForSignal(\$signalPath);

\$manager = app(App\Library\Usage\UsageWalletManager::class);
\$manager->applyProviderRefund(\$businessId, true, \$fundingAttemptId, 100, '100', 'ch_race_'.\$fundingAttemptId);
fwrite(STDOUT, "DONE\\n");
PHP;
    }

    public function test_forced_concurrent_refund_attempts_cannot_over_refund_the_refundable_paid_counter(): void
    {
        $business = $this->business();
        $this->creditPaid($business->id, 100, 2001);

        $this->runnerPath = sys_get_temp_dir().'/rpa_race_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->refundRunnerScript());
        $this->signalPath = sys_get_temp_dir().'/rpa_race_signal_'.uniqid().'.flag';

        // Two DIFFERENT funding attempts race a refund of 100 each against
        // the SAME wallet, whose refundable_paid_available_micro only
        // holds 100 total.
        $processOne = new Process([$this->phpBinary(), $this->runnerPath, (string) $business->id, '2001', $this->signalPath]);
        $processTwo = new Process([$this->phpBinary(), $this->runnerPath, (string) $business->id, '2002', $this->signalPath]);
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

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro, 'The counter must never go negative under a genuine race.');
        $this->assertGreaterThanOrEqual(0, (int) $wallet->available_balance_micro);

        $totalDebited = (int) DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'refund')->sum('gross_amount_micro');
        $this->assertLessThanOrEqual(100, abs((int) DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'refund')->sum('available_delta_micro')), 'Total wallet debit across both racing refunds must never exceed the original 100.');
    }

    public function test_refundable_paid_available_remains_non_negative_and_never_exceeds_available_balance(): void
    {
        $business = $this->business();
        $this->activateRate($business->id, 'rpa_test_14', '10000000');
        $this->creditPaid($business->id, 100, 1014);

        $wallet = $this->wallet($business->id);
        $this->assertGreaterThanOrEqual(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertLessThanOrEqual((int) $wallet->available_balance_micro, (int) $wallet->refundable_paid_available_micro);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'rpa_test_14', (string) Str::uuid(), '0.000006');
        $wallet = $this->wallet($business->id);
        $this->assertGreaterThanOrEqual(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertLessThanOrEqual((int) $wallet->available_balance_micro, (int) $wallet->refundable_paid_available_micro);

        app(UsageWalletManager::class)->commit($reservation->reservationId, '0.000009');
        $wallet = $this->wallet($business->id);
        $this->assertGreaterThanOrEqual(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertLessThanOrEqual((int) $wallet->available_balance_micro, (int) $wallet->refundable_paid_available_micro);

        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 500, $this->makeAdmin(), 'Promo.', (string) Str::uuid());
        $wallet = $this->wallet($business->id);
        $this->assertGreaterThanOrEqual(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertLessThanOrEqual((int) $wallet->available_balance_micro, (int) $wallet->refundable_paid_available_micro);
    }

    private function makeAdmin(): int
    {
        $id = User::create([
            'first_name' => 'Admin', 'last_name' => 'Actor', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
        $this->createdUserIds[] = $id;

        return $id;
    }
}
