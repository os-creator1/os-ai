<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §5/§9 — dispute balance-transaction cardinality
 * validation and reversal-lineage resolution.
 */
class DisputeBalanceTransactionValidationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    private function attemptWithVerifiedSuccess(string $paymentIntentId, string $chargeId): BusinessFundingAttempt
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, $paymentIntentId, $paymentMethodId,
            'https://fake.stripe.test/receipts/'.$chargeId, $chargeId,
        ));

        $manager->confirmAttemptFromReturn($attempt);

        return app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
    }

    private function disputeEvent(string $eventType, string $chargeId, array $balanceTransactions, string $disputeId = null): PaymentProviderEvent
    {
        $disputeId ??= 'dp_fake_'.uniqid();

        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => $eventType,
            'provider_object_id' => $disputeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $disputeId,
                'charge' => $chargeId,
                'payment_intent' => null,
                'currency' => 'usd',
                'status' => 'lost',
                'balance_transactions' => $balanceTransactions,
            ]]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ]);
    }

    private function process(PaymentProviderEvent $event): PaymentProviderEvent
    {
        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        return PaymentProviderEvent::find($event->id);
    }

    public function test_a_dispute_with_the_documented_single_withdrawal_transaction_is_processed(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess('pi_fake_bt1', 'ch_fake_bt1');
        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_bt1', [
            ['id' => 'txn_withdraw_1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('processed', $fresh->state->value);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->count());
    }

    public function test_a_dispute_carrying_the_documented_withdrawal_then_reinstatement_two_transaction_shape_processes_both_correctly(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess('pi_fake_bt2', 'ch_fake_bt2');
        $disputeId = 'dp_fake_bt2';
        $withdrawEvent = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_bt2', [
            ['id' => 'txn_withdraw_2', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ], $disputeId);
        $this->process($withdrawEvent);

        $reinstateEvent = $this->disputeEvent('charge.dispute.funds_reinstated', 'ch_fake_bt2', [
            ['id' => 'txn_withdraw_2', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
            ['id' => 'txn_reinstate_2', 'amount' => 1_000_000, 'currency' => 'usd', 'net' => 1_000_000, 'type' => 'adjustment'],
        ], $disputeId);
        $fresh = $this->process($reinstateEvent);

        $this->assertSame('processed', $fresh->state->value);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->count());
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'correction_reversal')->count());
    }

    public function test_more_than_two_balance_transactions_fails_closed_as_malformed(): void
    {
        $this->attemptWithVerifiedSuccess('pi_fake_bt3', 'ch_fake_bt3');
        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_bt3', [
            ['id' => 'txn_a', 'amount' => -500_000, 'currency' => 'usd', 'net' => -500_000, 'type' => 'adjustment'],
            ['id' => 'txn_b', 'amount' => 500_000, 'currency' => 'usd', 'net' => 500_000, 'type' => 'adjustment'],
            ['id' => 'txn_c', 'amount' => -100_000, 'currency' => 'usd', 'net' => -100_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('malformed_balance_transaction_array', $fresh->last_error);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('entry_type', 'dispute_chargeback')->count());
    }

    public function test_two_balance_transactions_of_the_same_sign_fail_closed_as_malformed(): void
    {
        $this->attemptWithVerifiedSuccess('pi_fake_bt4', 'ch_fake_bt4');
        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_bt4', [
            ['id' => 'txn_d', 'amount' => -500_000, 'currency' => 'usd', 'net' => -500_000, 'type' => 'adjustment'],
            ['id' => 'txn_e', 'amount' => -100_000, 'currency' => 'usd', 'net' => -100_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('malformed_balance_transaction_array', $fresh->last_error);
    }

    public function test_duplicate_balance_transaction_ids_in_the_array_fail_closed_as_malformed(): void
    {
        $this->attemptWithVerifiedSuccess('pi_fake_bt5', 'ch_fake_bt5');
        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_bt5', [
            ['id' => 'txn_dup', 'amount' => -500_000, 'currency' => 'usd', 'net' => -500_000, 'type' => 'adjustment'],
            ['id' => 'txn_dup', 'amount' => 500_000, 'currency' => 'usd', 'net' => 500_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('malformed_balance_transaction_array', $fresh->last_error);
    }

    public function test_a_reinstatement_resolves_the_original_chargeback_via_the_withdrawal_transactions_own_correlation_key_and_sets_the_exact_reversed_entry_id(): void
    {
        $attempt = $this->attemptWithVerifiedSuccess('pi_fake_bt6', 'ch_fake_bt6');
        $disputeId = 'dp_fake_bt6';
        $withdrawEvent = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_bt6', [
            ['id' => 'txn_withdraw_6', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ], $disputeId);
        $this->process($withdrawEvent);

        $originalEntry = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->first();

        $reinstateEvent = $this->disputeEvent('charge.dispute.funds_reinstated', 'ch_fake_bt6', [
            ['id' => 'txn_withdraw_6', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
            ['id' => 'txn_reinstate_6', 'amount' => 1_000_000, 'currency' => 'usd', 'net' => 1_000_000, 'type' => 'adjustment'],
        ], $disputeId);
        $this->process($reinstateEvent);

        $reversalEntry = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)->where('entry_type', 'correction_reversal')->first();

        $this->assertSame($originalEntry->id, $reversalEntry->reversed_entry_id);
    }

    public function test_a_reinstatement_with_no_matching_withdrawal_present_in_the_array_fails_closed_with_zero_mutation(): void
    {
        $this->attemptWithVerifiedSuccess('pi_fake_bt7', 'ch_fake_bt7');
        $event = $this->disputeEvent('charge.dispute.funds_reinstated', 'ch_fake_bt7', [
            ['id' => 'txn_orphan_positive', 'amount' => 1_000_000, 'currency' => 'usd', 'net' => 1_000_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('missing_original_chargeback_reference', $fresh->last_error);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('entry_type', 'correction_reversal')->count());
    }
}
