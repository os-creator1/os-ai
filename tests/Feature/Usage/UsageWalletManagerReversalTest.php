<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\BillingStatusTransitionSource;
use App\Events\Usage\BusinessWalletCredited;
use App\Events\Usage\BusinessWalletDebited;
use App\Events\Usage\BusinessWalletDebtCleared;
use App\Events\Usage\BusinessWalletDebtIncurred;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Jobs\Usage\SendLowBalanceNotification;
use App\Jobs\Usage\SendReceiptNotification;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §6/§8/§9/§10/§12 — direct, single-process proofs
 * of UsageWalletManager's own three reversal methods, mirroring
 * RefundablePaidAvailableAccountingTest's established direct-call
 * technique.
 */
class UsageWalletManagerReversalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    private function seedWallet(int $businessId, array $attributes): void
    {
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update($attributes);
    }

    private function wallet(int $businessId): object
    {
        return DB::table('business_usage_wallets')->where('business_id', $businessId)->first();
    }

    private function fundedBusiness(int $availableMicro, ?int $refundableMicro = null): Business
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->seedWallet($business->id, [
            'available_balance_micro' => $availableMicro,
            'refundable_paid_available_micro' => $refundableMicro ?? $availableMicro,
        ]);

        return $business;
    }

    // --- applyProviderRefund() ---

    public function test_apply_provider_refund_debits_available_balance_only_when_sufficient_and_within_the_refundable_paid_cap(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 2_000_000, '2000000', 'ch_fake_1');

        $wallet = $this->wallet($business->id);
        $this->assertSame(3_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(3_000_000, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
    }

    public function test_apply_provider_refund_debits_only_the_lesser_of_available_balance_and_refundable_paid_available_and_records_policy_excess_for_the_remainder(): void
    {
        $business = $this->fundedBusiness(5_000_000, 1_000_000);

        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 3_000_000, '3000000', 'ch_fake_2');

        $wallet = $this->wallet($business->id);
        $this->assertSame(4_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro);
        $this->assertSame(-1_000_000, (int) $ledgerEntry->available_delta_micro);
    }

    public function test_apply_provider_refund_never_writes_a_non_zero_debt_delta_micro(): void
    {
        $business = $this->fundedBusiness(0, 0);

        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 5_000_000, '5000000', 'ch_fake_3');

        $this->assertSame(0, (int) $ledgerEntry->debt_delta_micro);
        $this->assertSame(0, (int) $this->wallet($business->id)->debt_balance_micro);
    }

    public function test_apply_provider_refund_returns_null_and_mutates_nothing_for_a_non_positive_amount(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        $result = app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 0, '0', 'ch_fake_4');

        $this->assertNull($result);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_apply_provider_refund_writes_a_zero_delta_row_when_not_wallet_backed(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        $ledgerEntry = app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, false, 1, 2_000_000, '2000000', 'ch_fake_5');

        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro);
        $this->assertSame(2_000_000, (int) $ledgerEntry->gross_amount_micro);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_apply_provider_refund_sets_the_low_balance_marker_when_the_debit_drops_the_balance_to_or_below_threshold(): void
    {
        Queue::fake();
        $business = $this->fundedBusiness(2_000_000);
        $this->seedWallet($business->id, ['auto_recharge_enabled' => true, 'auto_recharge_threshold_micro' => 1_000_000]);

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 1_500_000, '1500000', 'ch_fake_6');

        $wallet = $this->wallet($business->id);
        $this->assertNotNull($wallet->low_balance_notified_at);
        Queue::assertPushed(SendLowBalanceNotification::class, 1);
    }

    public function test_apply_provider_refund_never_dispatches_evaluate_business_auto_recharge(): void
    {
        Queue::fake();
        $business = $this->fundedBusiness(2_000_000);
        $this->seedWallet($business->id, ['auto_recharge_enabled' => true, 'auto_recharge_threshold_micro' => 1_000_000]);

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 1_500_000, '1500000', 'ch_fake_7');

        Queue::assertNotPushed(EvaluateBusinessAutoRecharge::class);
    }

    public function test_apply_provider_refund_suspends_billing_using_the_provider_refund_mismatch_source_only_when_policy_excess_exists(): void
    {
        Event::fake([\App\Events\Usage\BusinessWalletBillingStatusChanged::class]);
        $business = $this->fundedBusiness(1_000_000, 1_000_000);

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 3_000_000, '3000000', 'ch_fake_8');

        $wallet = $this->wallet($business->id);
        $this->assertSame('suspended', $wallet->billing_status);
        $transition = DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->latest('id')->first();
        $this->assertSame(BillingStatusTransitionSource::ProviderRefundMismatch->value, $transition->source);
    }

    public function test_apply_provider_refund_does_not_re_suspend_an_already_suspended_wallet_on_a_repeated_policy_excess_outcome(): void
    {
        $business = $this->fundedBusiness(1_000_000, 1_000_000);

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 3_000_000, '3000000', 'ch_fake_9');
        $transitionCountAfterFirst = DB::table('business_usage_wallet_billing_status_transitions')->count();

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 2_000_000, '5000000', 'ch_fake_9');

        $this->assertSame($transitionCountAfterFirst, DB::table('business_usage_wallet_billing_status_transitions')->count());
    }

    public function test_apply_provider_refund_dispatches_business_wallet_debited_only_for_the_actual_debit_never_when_it_is_zero(): void
    {
        Event::fake([BusinessWalletDebited::class]);
        $business = $this->fundedBusiness(0, 0);

        app(UsageWalletManager::class)->applyProviderRefund((int) $business->id, true, 1, 1_000_000, '1000000', 'ch_fake_10');

        Event::assertNotDispatched(BusinessWalletDebited::class);
    }

    // --- applyDisputeWithdrawal() ---

    public function test_apply_dispute_withdrawal_debits_available_balance_when_sufficient(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 2_000_000, 'dp_fake_1', 'txn_1');

        $wallet = $this->wallet($business->id);
        $this->assertSame(3_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
    }

    public function test_apply_dispute_withdrawal_creates_debt_when_available_balance_is_insufficient(): void
    {
        $business = $this->fundedBusiness(1_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 3_000_000, 'dp_fake_2', 'txn_2');

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->available_balance_micro);
        $this->assertSame(2_000_000, (int) $wallet->debt_balance_micro);
    }

    public function test_apply_dispute_withdrawal_dispatches_business_wallet_debited_and_or_debt_incurred_matching_the_split(): void
    {
        Event::fake([BusinessWalletDebited::class, BusinessWalletDebtIncurred::class]);
        $business = $this->fundedBusiness(1_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 3_000_000, 'dp_fake_3', 'txn_3');

        Event::assertDispatched(BusinessWalletDebited::class);
        Event::assertDispatched(BusinessWalletDebtIncurred::class);
    }

    public function test_apply_dispute_withdrawal_suspends_billing_status(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 1_000_000, 'dp_fake_4', 'txn_4');

        $this->assertSame('suspended', $this->wallet($business->id)->billing_status);
    }

    public function test_apply_dispute_withdrawal_does_not_re_suspend_an_already_suspended_wallet(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 1_000_000, 'dp_fake_5', 'txn_5');
        $transitionCountAfterFirst = DB::table('business_usage_wallet_billing_status_transitions')->count();

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 500_000, 'dp_fake_5', 'txn_5b');

        $this->assertSame($transitionCountAfterFirst, DB::table('business_usage_wallet_billing_status_transitions')->count());
    }

    public function test_apply_dispute_withdrawal_suspends_billing_even_when_not_wallet_backed(): void
    {
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, false, 1, 1_000_000, 'dp_fake_6', 'txn_6');

        $this->assertSame('suspended', $this->wallet($business->id)->billing_status);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_apply_dispute_withdrawal_never_dispatches_evaluate_business_auto_recharge(): void
    {
        Queue::fake();
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 1_000_000, 'dp_fake_7', 'txn_7');

        Queue::assertNotPushed(EvaluateBusinessAutoRecharge::class);
    }

    public function test_apply_dispute_withdrawal_never_dispatches_send_receipt_notification_or_writes_a_receipt_row(): void
    {
        Queue::fake();
        $business = $this->fundedBusiness(5_000_000);

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, 1, 1_000_000, 'dp_fake_8', 'txn_8');

        Queue::assertNotPushed(SendReceiptNotification::class);
        $this->assertSame(0, DB::table('business_billing_receipts')->count());
    }

    // --- reinstateDisputedFunds() ---

    public function test_reinstate_disputed_funds_clears_current_debt_before_crediting_available_balance(): void
    {
        $business = $this->fundedBusiness(0, 0);
        $this->seedWallet($business->id, ['debt_balance_micro' => 1_000_000]);

        app(UsageWalletManager::class)->reinstateDisputedFunds((int) $business->id, true, 1, 3_000_000, 1_000_000, null, 'dp_fake_9', 'txn_9');

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame(2_000_000, (int) $wallet->available_balance_micro);
    }

    public function test_reinstate_disputed_funds_never_produces_negative_debt_when_debt_was_already_cleared(): void
    {
        $business = $this->fundedBusiness(0, 0);

        app(UsageWalletManager::class)->reinstateDisputedFunds((int) $business->id, true, 1, 2_000_000, 0, null, 'dp_fake_10', 'txn_10');

        $this->assertSame(0, (int) $this->wallet($business->id)->debt_balance_micro);
    }

    public function test_reinstate_disputed_funds_dispatches_business_wallet_credited_and_or_debt_cleared_matching_current_state(): void
    {
        Event::fake([BusinessWalletCredited::class, BusinessWalletDebtCleared::class]);
        $business = $this->fundedBusiness(0, 0);
        $this->seedWallet($business->id, ['debt_balance_micro' => 1_000_000]);

        app(UsageWalletManager::class)->reinstateDisputedFunds((int) $business->id, true, 1, 3_000_000, 1_000_000, null, 'dp_fake_11', 'txn_11');

        Event::assertDispatched(BusinessWalletCredited::class);
        Event::assertDispatched(BusinessWalletDebtCleared::class);
    }

    public function test_reinstate_disputed_funds_clears_the_low_balance_marker_on_recovery(): void
    {
        $business = $this->fundedBusiness(0, 0);
        $this->seedWallet($business->id, [
            'low_balance_notified_at' => now(),
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 1_000_000,
        ]);

        app(UsageWalletManager::class)->reinstateDisputedFunds((int) $business->id, true, 1, 2_000_000, 0, null, 'dp_fake_12', 'txn_12');

        $this->assertNull($this->wallet($business->id)->low_balance_notified_at);
    }

    public function test_reinstate_disputed_funds_remains_zero_delta_and_never_credits_the_wallet_when_not_wallet_backed(): void
    {
        $business = $this->fundedBusiness(0, 0);

        $ledgerEntry = app(UsageWalletManager::class)->reinstateDisputedFunds((int) $business->id, false, 1, 2_000_000, 0, null, 'dp_fake_13', 'txn_13');

        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro);
        $this->assertSame(0, (int) $ledgerEntry->debt_delta_micro);
        $this->assertSame(0, (int) $this->wallet($business->id)->available_balance_micro);
    }
}
