<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
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
use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §8/§9/§11/§13/§20 — dispute withdrawal/
 * reinstatement outcome processing, multi-dispute funding-attempt state,
 * and the §16 audit-only lifecycle events.
 */
class ProviderDisputeOutcomeTest extends TestCase
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

    /**
     * @return array{0: \App\Models\Customer, 1: Business}
     */
    private function businessWithProviderCustomer(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        return [$customer, $business];
    }

    private function topUpAttempt(Business $business, int $customerId, int $amountMicro, string $chargeId): BusinessFundingAttempt
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customerId, $amountMicro);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_fake_'.uniqid(), $paymentMethodId,
            'https://fake.stripe.test/receipts/'.$chargeId, $chargeId,
        ));

        $manager->confirmAttemptFromReturn($attempt);

        return app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
    }

    private function microToMinorUnitsUsd(int $amountMicro): int
    {
        return intdiv($amountMicro, 10_000);
    }

    private function disputeEvent(string $eventType, string $chargeId, array $balanceTransactionsMicro, ?string $disputeId = null, ?string $providerEventId = null, ?string $status = 'lost'): PaymentProviderEvent
    {
        $disputeId ??= 'dp_fake_'.uniqid();
        $balanceTransactions = array_map(fn (array $bt) => [
            'id' => $bt['id'], 'amount' => $bt['amount'] < 0 ? -$this->microToMinorUnitsUsd(abs($bt['amount'])) : $this->microToMinorUnitsUsd($bt['amount']),
            'currency' => $bt['currency'] ?? 'usd', 'net' => $bt['amount'], 'type' => 'adjustment',
        ], $balanceTransactionsMicro);

        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId ?? 'evt_'.uniqid(),
            'event_type' => $eventType,
            'provider_object_id' => $disputeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $disputeId, 'charge' => $chargeId, 'payment_intent' => null, 'currency' => 'usd',
                'status' => $status, 'balance_transactions' => $balanceTransactions,
            ]]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function withdraw(string $chargeId, int $amountMicro, string $disputeId, ?string $txnId = null): PaymentProviderEvent
    {
        return $this->disputeEvent('charge.dispute.funds_withdrawn', $chargeId, [
            ['id' => $txnId ?? 'txn_w_'.uniqid(), 'amount' => -$amountMicro],
        ], $disputeId);
    }

    private function reinstate(string $chargeId, int $amountMicro, string $disputeId, string $withdrawTxnId, ?string $reinstateTxnId = null): PaymentProviderEvent
    {
        return $this->disputeEvent('charge.dispute.funds_reinstated', $chargeId, [
            ['id' => $withdrawTxnId, 'amount' => -10_000],
            ['id' => $reinstateTxnId ?? 'txn_r_'.uniqid(), 'amount' => $amountMicro],
        ], $disputeId);
    }

    private function process(PaymentProviderEvent $event): PaymentProviderEvent
    {
        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        return PaymentProviderEvent::find($event->id);
    }

    private function wallet(int $businessId): object
    {
        return DB::table('business_usage_wallets')->where('business_id', $businessId)->first();
    }

    public function test_a_funds_withdrawn_event_applies_the_signed_balance_transaction_amount_as_a_dispute_chargeback(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d1');

        $this->process($this->withdraw('ch_fake_d1', 2_000_000, 'dp_fake_d1'));

        $this->assertSame(3_000_000, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->count());
    }

    public function test_a_funds_withdrawn_event_uses_the_balance_transaction_amount_not_the_disputed_claim_amount(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d2');

        // The dispute object's own top-level 'amount' (the cardholder's
        // claimed figure, §2) is never read by this job at all — only the
        // balance_transactions[] amount is. This fixture omits any
        // top-level 'amount' entirely, proving the job never needs it.
        $this->process($this->withdraw('ch_fake_d2', 1_500_000, 'dp_fake_d2'));

        $this->assertSame(3_500_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_a_dispute_exceeding_available_balance_clears_available_balance_and_creates_debt(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 2_000_000, 'ch_fake_d3');

        $this->process($this->withdraw('ch_fake_d3', 5_000_000, 'dp_fake_d3'));

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->available_balance_micro);
        $this->assertSame(3_000_000, (int) $wallet->debt_balance_micro);
    }

    public function test_a_replayed_funds_withdrawn_event_for_the_same_balance_transaction_produces_zero_additional_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d4');

        $this->process($this->withdraw('ch_fake_d4', 2_000_000, 'dp_fake_d4', 'txn_d4'));
        $balanceAfterFirst = (int) $this->wallet($business->id)->available_balance_micro;

        $this->process($this->withdraw('ch_fake_d4', 2_000_000, 'dp_fake_d4', 'txn_d4'));

        $this->assertSame($balanceAfterFirst, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('entry_type', 'dispute_chargeback')->count());
    }

    public function test_a_funds_withdrawn_event_with_an_empty_balance_transactions_array_produces_no_mutation_and_is_durably_audited(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d5');

        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_d5', [], 'dp_fake_d5');
        $fresh = $this->process($event);

        $this->assertSame('ignored', $fresh->state->value);
        $this->assertSame('dispute_audit_only', $fresh->normalized_outcome);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_a_funds_reinstated_event_applies_the_signed_balance_transaction_amount_as_a_correction_reversal(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d6');
        $this->process($this->withdraw('ch_fake_d6', 2_000_000, 'dp_fake_d6', 'txn_d6w'));

        $this->process($this->reinstate('ch_fake_d6', 2_000_000, 'dp_fake_d6', 'txn_d6w', 'txn_d6r'));

        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'correction_reversal')->count());
    }

    public function test_a_partial_reinstatement_clears_only_part_of_the_outstanding_dispute_exposure_and_leaves_the_attempt_disputed(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d7');
        $this->process($this->withdraw('ch_fake_d7', 3_000_000, 'dp_fake_d7', 'txn_d7w'));

        $this->process($this->reinstate('ch_fake_d7', 1_000_000, 'dp_fake_d7', 'txn_d7w', 'txn_d7r'));

        $this->assertSame(3_000_000, (int) $this->wallet($business->id)->available_balance_micro);
        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Disputed, $fresh->state);
    }

    public function test_a_full_reinstatement_after_debt_was_already_cleared_by_an_intervening_top_up_credits_available_balance_without_creating_negative_debt(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 2_000_000, 'ch_fake_d8');
        $this->process($this->withdraw('ch_fake_d8', 5_000_000, 'dp_fake_d8', 'txn_d8w'));
        $this->assertSame(3_000_000, (int) $this->wallet($business->id)->debt_balance_micro);

        // An intervening, unrelated credit clears the debt directly.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['debt_balance_micro' => 0]);

        $this->process($this->reinstate('ch_fake_d8', 5_000_000, 'dp_fake_d8', 'txn_d8w', 'txn_d8r'));

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame(5_000_000, (int) $wallet->available_balance_micro);
    }

    public function test_a_reinstatement_clears_current_debt_first_then_credits_any_remainder_to_available_balance(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 2_000_000, 'ch_fake_d9');
        $this->process($this->withdraw('ch_fake_d9', 5_000_000, 'dp_fake_d9', 'txn_d9w'));

        $this->process($this->reinstate('ch_fake_d9', 5_000_000, 'dp_fake_d9', 'txn_d9w', 'txn_d9r'));

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame(2_000_000, (int) $wallet->available_balance_micro);
    }

    public function test_a_reinstatement_is_bounded_to_the_actual_withdrawn_amount_for_that_specific_dispute(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d10');
        $this->process($this->withdraw('ch_fake_d10', 2_000_000, 'dp_fake_d10', 'txn_d10w'));

        // Report an implausibly large reinstatement — bounded to the
        // dispute's own actual withdrawn-minus-reinstated amount (2,000,000).
        $this->process($this->reinstate('ch_fake_d10', 50_000_000, 'dp_fake_d10', 'txn_d10w', 'txn_d10r'));

        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_a_duplicate_reinstatement_event_for_the_same_balance_transaction_produces_zero_additional_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d11');
        $this->process($this->withdraw('ch_fake_d11', 2_000_000, 'dp_fake_d11', 'txn_d11w'));
        $this->process($this->reinstate('ch_fake_d11', 2_000_000, 'dp_fake_d11', 'txn_d11w', 'txn_d11r'));
        $balanceAfterFirst = (int) $this->wallet($business->id)->available_balance_micro;

        $this->process($this->reinstate('ch_fake_d11', 2_000_000, 'dp_fake_d11', 'txn_d11w', 'txn_d11r'));

        $this->assertSame($balanceAfterFirst, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('entry_type', 'correction_reversal')->count());
    }

    public function test_a_reinstatement_dispatches_business_wallet_credited_and_or_debt_cleared_matching_the_current_state_based_split(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 2_000_000, 'ch_fake_d12');
        $this->process($this->withdraw('ch_fake_d12', 5_000_000, 'dp_fake_d12', 'txn_d12w'));

        Event::fake([\App\Events\Usage\BusinessWalletCredited::class, \App\Events\Usage\BusinessWalletDebtCleared::class]);
        $this->process($this->reinstate('ch_fake_d12', 5_000_000, 'dp_fake_d12', 'txn_d12w', 'txn_d12r'));

        Event::assertDispatched(\App\Events\Usage\BusinessWalletCredited::class);
        Event::assertDispatched(\App\Events\Usage\BusinessWalletDebtCleared::class);
    }

    public function test_many_dispute_references_for_the_same_attempt_with_one_still_outstanding_leaves_the_attempt_disputed(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d13');

        $this->process($this->withdraw('ch_fake_d13', 1_000_000, 'dp_fake_d13a', 'txn_d13aw'));
        $this->process($this->reinstate('ch_fake_d13', 1_000_000, 'dp_fake_d13a', 'txn_d13aw', 'txn_d13ar'));
        $this->process($this->withdraw('ch_fake_d13', 1_000_000, 'dp_fake_d13b', 'txn_d13bw'));

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Disputed, $fresh->state);
    }

    public function test_all_disputes_for_the_attempt_cleared_falls_back_to_the_refund_progress_based_state(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d14');

        $this->process($this->withdraw('ch_fake_d14', 1_000_000, 'dp_fake_d14', 'txn_d14w'));
        $this->process($this->reinstate('ch_fake_d14', 1_000_000, 'dp_fake_d14', 'txn_d14w', 'txn_d14r'));

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $fresh->state);
    }

    public function test_a_lost_dispute_leaves_the_attempt_disputed_permanently_with_no_reversal(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d15');

        $this->process($this->withdraw('ch_fake_d15', 2_000_000, 'dp_fake_d15', 'txn_d15w'));
        $this->process($this->disputeEvent('charge.dispute.closed', 'ch_fake_d15', [], 'dp_fake_d15', null, 'lost'));

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Disputed, $fresh->state);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('entry_type', 'correction_reversal')->count());
    }

    public function test_a_dispute_created_event_is_durably_recorded_and_ignored_with_no_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d16');

        $fresh = $this->process($this->disputeEvent('charge.dispute.created', 'ch_fake_d16', [], 'dp_fake_d16'));

        $this->assertSame('ignored', $fresh->state->value);
        $this->assertSame('dispute_audit_only', $fresh->normalized_outcome);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_a_dispute_updated_event_is_durably_recorded_and_ignored_with_no_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d17');

        $fresh = $this->process($this->disputeEvent('charge.dispute.updated', 'ch_fake_d17', [], 'dp_fake_d17'));

        $this->assertSame('ignored', $fresh->state->value);
        $this->assertSame('dispute_audit_only', $fresh->normalized_outcome);
    }

    public function test_a_dispute_closed_event_is_durably_recorded_and_ignored_with_no_mutation_regardless_of_status(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d18');

        $fresh = $this->process($this->disputeEvent('charge.dispute.closed', 'ch_fake_d18', [], 'dp_fake_d18', null, 'won'));

        $this->assertSame('ignored', $fresh->state->value);
        $this->assertSame('dispute_audit_only', $fresh->normalized_outcome);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_a_dispute_event_for_an_unresolvable_reference_fails_with_no_mutation(): void
    {
        $event = $this->withdraw('ch_fake_nowhere_'.uniqid(), 1_000_000, 'dp_fake_nowhere');

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('no_matching_local_record', $fresh->last_error);
    }

    public function test_a_dispute_never_affects_an_unrelated_businesss_wallet(): void
    {
        [$customerA, $businessA] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($businessA, $customerA->user_id, 5_000_000, 'ch_fake_d20a');
        [$customerB, $businessB] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($businessB, $customerB->user_id, 5_000_000, 'ch_fake_d20b');

        $this->process($this->withdraw('ch_fake_d20a', 2_000_000, 'dp_fake_d20'));

        $this->assertSame(3_000_000, (int) $this->wallet($businessA->id)->available_balance_micro);
        $this->assertSame(5_000_000, (int) $this->wallet($businessB->id)->available_balance_micro);
    }

    public function test_a_second_dispute_while_billing_is_already_suspended_writes_no_redundant_suspended_transition(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_d21');
        $this->process($this->withdraw('ch_fake_d21', 1_000_000, 'dp_fake_d21a', 'txn_d21aw'));
        $wallet = $this->wallet($business->id);
        $transitionCountAfterFirst = DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->count();

        $this->process($this->withdraw('ch_fake_d21', 1_000_000, 'dp_fake_d21b', 'txn_d21bw'));

        $this->assertSame($transitionCountAfterFirst, DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->count());
    }
}
