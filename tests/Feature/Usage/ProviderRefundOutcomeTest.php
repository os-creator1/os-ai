<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Jobs\Usage\SendChargebackDisputeNotification;
use App\Jobs\Usage\SendReceiptNotification;
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
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §6/§12/§13/§17 — charge.refunded outcome
 * processing: the corrected refundable-paid cap, the out-of-order/replay
 * clamp, ledger row shape, funding-attempt state recomputation, and the
 * §17 exclusions.
 */
class ProviderRefundOutcomeTest extends TestCase
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

    private function topUpAttempt(Business $business, int $customerId, int $amountMicro = 5_000_000, ?string $chargeId = null, ?string $paymentIntentId = null): BusinessFundingAttempt
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
            $attempt->provider_customer_external_id_snapshot, $paymentIntentId ?? 'pi_fake_'.uniqid(), $paymentMethodId,
            'https://fake.stripe.test/receipts/'.($chargeId ?? 'ch_fake_'.uniqid()), $chargeId ?? 'ch_fake_'.uniqid(),
        ));

        $manager->confirmAttemptFromReturn($attempt);

        return app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
    }

    private function activateRate(string $featureKey = 'crm', string $retailRateMicro = '1000000'): int
    {
        $actorId = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Actor', 'email' => 'actor'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey, 'feature_key' => $featureKey, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Refund outcome fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate($featureKey, $retailRateMicro, '500000', 'per message', $currencyId, $actorId, 'Test rate activation.');
        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Test metering activation.');

        return $actorId;
    }

    /**
     * USD is always exactly 2 decimal places in this fixture, so 10,000
     * micro-units per cent is a fixed, exact ratio — never a magic guess.
     */
    private function microToMinorUnitsUsd(int $amountMicro): int
    {
        return intdiv($amountMicro, 10_000);
    }

    /**
     * $cumulativeAmountRefundedMicro is expressed in micro-units for this
     * fixture's own convenience — converted here to the minor-units figure
     * Stripe's own amount_refunded field actually carries (§14), never
     * passed through as-is.
     */
    private function refundEvent(string $chargeId, int $cumulativeAmountRefundedMicro, ?string $paymentIntentId = null, ?string $currency = 'usd', ?string $providerEventId = null): PaymentProviderEvent
    {
        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId ?? 'evt_'.uniqid(),
            'event_type' => 'charge.refunded',
            'provider_object_id' => $chargeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $chargeId, 'payment_intent' => $paymentIntentId, 'amount_refunded' => $this->microToMinorUnitsUsd($cumulativeAmountRefundedMicro), 'currency' => $currency,
            ]]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
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

    public function test_a_refund_within_available_balance_debits_available_only_and_creates_no_debt(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r1');

        $this->process($this->refundEvent('ch_fake_r1', 2_000_000));

        $wallet = $this->wallet($business->id);
        $this->assertSame(3_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
    }

    public function test_a_full_refund_after_partial_usage_consumption_removes_only_the_remaining_available_balance_creates_no_debt_records_policy_excess_and_suspends_billing(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->activateRate();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r2');

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '3');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '3');

        $this->process($this->refundEvent('ch_fake_r2', 5_000_000));

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame('suspended', $wallet->billing_status);
        $event = PaymentProviderEvent::whereNotNull('normalized_outcome')->latest('id')->first();
        $this->assertGreaterThan(0, $event->normalized_policy_excess_micro);
    }

    public function test_a_refund_when_available_balance_is_zero_creates_no_debt_or_wallet_debit_event_but_records_the_outcome_and_policy_excess(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r3');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => 0, 'refundable_paid_available_micro' => 0,
        ]);

        $fresh = $this->process($this->refundEvent('ch_fake_r3', 5_000_000));

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame('processed', $fresh->state->value);
        $this->assertGreaterThan(0, $fresh->normalized_policy_excess_micro);
    }

    public function test_no_refund_ledger_row_can_ever_have_a_non_zero_debt_delta_micro(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r4');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 0, 'refundable_paid_available_micro' => 0]);

        $this->process($this->refundEvent('ch_fake_r4', 5_000_000));

        $entry = DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->first();
        $this->assertSame(0, (int) $entry->debt_delta_micro);
    }

    public function test_a_second_partial_refund_event_applies_only_the_incremental_delta_since_the_first(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r5');

        $this->process($this->refundEvent('ch_fake_r5', 1_000_000));
        $this->process($this->refundEvent('ch_fake_r5', 3_000_000));

        $this->assertSame(2_000_000, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(2, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_an_equal_cumulative_replayed_refund_event_produces_zero_additional_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r6');

        $this->process($this->refundEvent('ch_fake_r6', 2_000_000));
        $balanceAfterFirst = (int) $this->wallet($business->id)->available_balance_micro;

        $this->process($this->refundEvent('ch_fake_r6', 2_000_000));

        $this->assertSame($balanceAfterFirst, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_a_strictly_lower_out_of_order_cumulative_refund_event_produces_a_clamped_zero_delta_never_a_negative_one(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r7');

        $this->process($this->refundEvent('ch_fake_r7', 3_000_000));
        $balanceAfterFirst = (int) $this->wallet($business->id)->available_balance_micro;

        $fresh = $this->process($this->refundEvent('ch_fake_r7', 1_000_000));

        $this->assertSame('processed', $fresh->state->value);
        $this->assertSame($balanceAfterFirst, (int) $this->wallet($business->id)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_a_refund_is_bounded_and_never_exceeds_the_original_funding_attempts_expected_amount(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r8');

        $this->process($this->refundEvent('ch_fake_r8', 50_000_000));

        $entry = DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->first();
        $this->assertSame(5_000_000, (int) $entry->gross_amount_micro);
    }

    public function test_a_wallet_credit_addon_purchase_refund_follows_the_identical_refundable_paid_cap(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-refund-addon', 'display_name' => 'Fixture Refund Add-on', 'price_micro' => 2_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'wallet_credit', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateAddonPurchase($business, 'fixture-refund-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult($paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_fake_addon_refund', $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_addon_refund', 'ch_fake_addon_refund',
        ));
        $manager->confirmAttemptFromReturn($attempt);

        $this->process($this->refundEvent('ch_fake_addon_refund', 2_000_000));

        $wallet = $this->wallet($business->id);
        $this->assertSame(0, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro);
    }

    public function test_a_refund_event_missing_both_payment_intent_and_charge_fails_with_no_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r10');

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => 'charge.refunded',
            'provider_object_id' => 'obj_'.uniqid(),
            'payload_encrypted' => json_encode(['data' => ['object' => ['id' => null, 'payment_intent' => null, 'amount_refunded' => 1_000_000, 'currency' => 'usd']]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('no_matching_local_record', $fresh->last_error);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_a_refund_event_for_an_unresolvable_reference_fails_with_no_mutation(): void
    {
        $fresh = $this->process($this->refundEvent('ch_fake_nowhere_'.uniqid(), 1_000_000));

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('no_matching_local_record', $fresh->last_error);
    }

    public function test_a_refund_event_with_a_mismatched_currency_fails_with_no_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r12');

        $fresh = $this->process($this->refundEvent('ch_fake_r12', 1_000_000, null, 'eur'));

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('currency_mismatch', $fresh->last_error);
        $this->assertSame(5_000_000, (int) $this->wallet($business->id)->available_balance_micro);
    }

    public function test_refund_progress_is_computed_solely_from_refund_entries_and_is_unaffected_by_a_dispute_on_the_same_attempt(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r13');

        app(UsageWalletManager::class)->applyDisputeWithdrawal((int) $business->id, true, (int) $attempt->id, 1_000_000, 'dp_fake_r13', 'txn_r13');

        $this->process($this->refundEvent('ch_fake_r13', 2_000_000));

        $refunded = (string) DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)->where('entry_type', 'refund')
            ->selectRaw('COALESCE(SUM(gross_amount_micro), 0) AS total')->value('total');
        $this->assertSame('2000000', $refunded);
    }

    public function test_a_refund_never_affects_an_unrelated_businesss_wallet(): void
    {
        [$customerA, $businessA] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($businessA, $customerA->user_id, 5_000_000, 'ch_fake_r14a');
        [$customerB, $businessB] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($businessB, $customerB->user_id, 5_000_000, 'ch_fake_r14b');

        $this->process($this->refundEvent('ch_fake_r14a', 2_000_000));

        $this->assertSame(3_000_000, (int) $this->wallet($businessA->id)->available_balance_micro);
        $this->assertSame(5_000_000, (int) $this->wallet($businessB->id)->available_balance_micro);
    }

    public function test_refund_reason_is_never_null_and_matches_the_deterministic_template(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r15');

        $this->process($this->refundEvent('ch_fake_r15', 2_000_000));

        $entry = DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->first();
        $this->assertNotNull($entry->reason);
        $this->assertStringContainsString('2000000', $entry->reason);
        $this->assertStringContainsString('ch_fake_r15', $entry->reason);
    }

    public function test_consumed_usage_and_committed_spend_history_are_never_reversed_by_a_refund(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->activateRate();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r16');

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');
        $committedBefore = (int) $this->wallet($business->id)->committed_spend_this_period_micro;

        $this->process($this->refundEvent('ch_fake_r16', 1_000_000));

        $this->assertSame($committedBefore, (int) $this->wallet($business->id)->committed_spend_this_period_micro);
    }

    public function test_manual_credit_and_promotional_credit_entries_are_never_treated_as_refundable_provider_funding(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->activateRate();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r17');

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '5');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '5');

        $actorId = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Actor', 'email' => 'actor'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 5_000_000, $actorId, 'Test promo.', (string) Str::uuid());

        $this->process($this->refundEvent('ch_fake_r17', 5_000_000));

        $wallet = $this->wallet($business->id);
        $this->assertGreaterThan(0, $wallet->available_balance_micro, 'The promotional credit itself remains spendable.');
        $this->assertSame(0, (int) $wallet->refundable_paid_available_micro, 'No promotional credit was ever converted into cash-refundable headroom.');
    }

    public function test_a_policy_excess_refund_event_is_marked_terminally_processed_rather_than_retried(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r18');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 0, 'refundable_paid_available_micro' => 0]);

        $fresh = $this->process($this->refundEvent('ch_fake_r18', 5_000_000));

        $this->assertSame('processed', $fresh->state->value);
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->lease_expires_at);
    }

    public function test_a_replayed_policy_excess_refund_event_creates_no_second_suspension_transition(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r19');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 0, 'refundable_paid_available_micro' => 0]);

        $this->process($this->refundEvent('ch_fake_r19', 5_000_000));
        $wallet = $this->wallet($business->id);
        $transitionCountAfterFirst = DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->count();

        $this->process($this->refundEvent('ch_fake_r19', 5_000_000));

        $this->assertSame($transitionCountAfterFirst, DB::table('business_usage_wallet_billing_status_transitions')->where('wallet_id', $wallet->id)->count());
    }

    public function test_a_policy_excess_refund_never_dispatches_evaluate_business_auto_recharge_send_receipt_notification_or_the_dedicated_chargeback_notification(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r20');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 0, 'refundable_paid_available_micro' => 0]);
        Queue::fake();

        $this->process($this->refundEvent('ch_fake_r20', 5_000_000));

        Queue::assertNotPushed(EvaluateBusinessAutoRecharge::class);
        Queue::assertNotPushed(SendReceiptNotification::class);
        Queue::assertNotPushed(SendChargebackDisputeNotification::class);
    }

    public function test_a_full_refund_after_a_policy_excess_partial_still_recomputes_the_attempt_to_refunded_from_gross_progress(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_r21');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 1_000_000, 'refundable_paid_available_micro' => 1_000_000]);

        $this->process($this->refundEvent('ch_fake_r21', 3_000_000));
        $this->process($this->refundEvent('ch_fake_r21', 5_000_000));

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Refunded, $fresh->state);
    }
}
