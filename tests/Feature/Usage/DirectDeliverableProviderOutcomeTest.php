<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\AddonPurchaseStatus;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Events\Usage\BusinessWalletCredited;
use App\Events\Usage\BusinessWalletDebited;
use App\Events\Usage\BusinessWalletDebtCleared;
use App\Events\Usage\BusinessWalletDebtIncurred;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Jobs\Usage\SendChargebackDisputeNotification;
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
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §4/§10/§17 — a direct_deliverable AddonPurchase's
 * own zero-wallet-delta refund/dispute outcome handling: real ledger/audit
 * rows and real risk-control decisions (suspension, notification), zero
 * balance-column movement, and permanently-Completed purchase status.
 */
class DirectDeliverableProviderOutcomeTest extends TestCase
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
     * @return array{0: BusinessFundingAttempt, 1: int, 2: string}
     */
    private function directDeliverableAttempt(int $priceMicro = 2_000_000): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $addonKey = 'fixture-direct-'.uniqid();
        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => $addonKey, 'display_name' => 'Fixture Direct Add-on', 'price_micro' => $priceMicro,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'direct_deliverable', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateAddonPurchase($business, $addonKey, $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $chargeId = 'ch_fake_'.uniqid();
        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult($paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_fake_'.uniqid(), $paymentMethodId,
            'https://fake.stripe.test/receipts/'.$chargeId, $chargeId,
        ));
        $manager->confirmAttemptFromReturn($attempt);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        return [$attempt, $result->addonPurchaseId, $chargeId];
    }

    private function microToMinorUnitsUsd(int $amountMicro): int
    {
        return intdiv($amountMicro, 10_000);
    }

    private function refundEvent(string $chargeId, int $cumulativeAmountRefundedMicro, ?string $providerEventId = null): PaymentProviderEvent
    {
        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId ?? 'evt_'.uniqid(),
            'event_type' => 'charge.refunded',
            'provider_object_id' => $chargeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $chargeId, 'payment_intent' => null, 'amount_refunded' => $this->microToMinorUnitsUsd($cumulativeAmountRefundedMicro), 'currency' => 'usd',
            ]]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function disputeEvent(string $eventType, string $chargeId, array $balanceTransactionsMicro, ?string $disputeId = null): PaymentProviderEvent
    {
        $disputeId ??= 'dp_fake_'.uniqid();
        $balanceTransactions = array_map(fn (array $bt) => [
            'id' => $bt['id'], 'amount' => $bt['amount'] < 0 ? -$this->microToMinorUnitsUsd(abs($bt['amount'])) : $this->microToMinorUnitsUsd($bt['amount']),
            'currency' => 'usd', 'net' => $bt['amount'], 'type' => 'adjustment',
        ], $balanceTransactionsMicro);

        return PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => $eventType, 'provider_object_id' => $disputeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $disputeId, 'charge' => $chargeId, 'payment_intent' => null, 'currency' => 'usd', 'status' => 'lost', 'balance_transactions' => $balanceTransactions,
            ]]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function process(PaymentProviderEvent $event): PaymentProviderEvent
    {
        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        return PaymentProviderEvent::find($event->id);
    }

    public function test_a_partial_direct_deliverable_refund_leaves_the_attempt_succeeded_with_zero_wallet_deltas_and_a_recorded_outcome_row(): void
    {
        [$attempt, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $fresh = $this->process($this->refundEvent($chargeId, 2_000_000));

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);
        $this->assertSame('processed', $fresh->state->value);
        $this->assertSame(0, (int) $fresh->normalized_wallet_delta_micro);
        $ledgerEntry = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'refund')->first();
        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro);
    }

    public function test_a_full_direct_deliverable_refund_transitions_the_attempt_to_refunded_with_zero_wallet_deltas(): void
    {
        [$attempt, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $this->process($this->refundEvent($chargeId, 5_000_000));

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Refunded, $freshAttempt->state);
    }

    public function test_a_replayed_direct_deliverable_refund_event_is_a_no_op(): void
    {
        [, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $this->process($this->refundEvent($chargeId, 2_000_000));
        $this->process($this->refundEvent($chargeId, 2_000_000));

        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_a_direct_deliverable_dispute_withdrawal_writes_a_zero_delta_dispute_chargeback_and_suspends_billing(): void
    {
        [$attempt, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', $chargeId, [
            ['id' => 'txn_dd1', 'amount' => -2_000_000],
        ]));

        $ledgerEntry = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->first();
        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro);
        $this->assertSame(0, (int) $ledgerEntry->debt_delta_micro);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->first();
        $this->assertSame('suspended', $wallet->billing_status);
    }

    public function test_a_direct_deliverable_dispute_reinstatement_writes_a_zero_delta_correction_reversal_and_never_credits_the_wallet(): void
    {
        [$attempt, , $chargeId] = $this->directDeliverableAttempt(5_000_000);
        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', $chargeId, [
            ['id' => 'txn_dd2w', 'amount' => -2_000_000],
        ], 'dp_fake_dd2'));

        $this->process($this->disputeEvent('charge.dispute.funds_reinstated', $chargeId, [
            ['id' => 'txn_dd2w', 'amount' => -10_000],
            ['id' => 'txn_dd2r', 'amount' => 2_000_000],
        ], 'dp_fake_dd2'));

        $ledgerEntry = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'correction_reversal')->first();
        $this->assertSame(0, (int) $ledgerEntry->available_delta_micro);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->first();
        $this->assertSame(0, (int) $wallet->available_balance_micro);
    }

    public function test_zero_wallet_balance_events_are_never_dispatched_for_any_direct_deliverable_outcome_row(): void
    {
        Event::fake([BusinessWalletDebited::class, BusinessWalletCredited::class, BusinessWalletDebtIncurred::class, BusinessWalletDebtCleared::class]);
        [, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $this->process($this->refundEvent($chargeId, 2_000_000));
        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', $chargeId, [
            ['id' => 'txn_dd3', 'amount' => -1_000_000],
        ], 'dp_fake_dd3'));

        Event::assertNotDispatched(BusinessWalletDebited::class);
        Event::assertNotDispatched(BusinessWalletCredited::class);
        Event::assertNotDispatched(BusinessWalletDebtIncurred::class);
        Event::assertNotDispatched(BusinessWalletDebtCleared::class);
    }

    public function test_a_direct_deliverable_dispute_withdrawal_dispatches_the_chargeback_notification_decision_despite_zero_wallet_deltas(): void
    {
        Queue::fake();
        [, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', $chargeId, [
            ['id' => 'txn_dd4', 'amount' => -1_000_000],
        ]));

        Queue::assertPushed(SendChargebackDisputeNotification::class, 1);
    }

    public function test_a_direct_deliverable_refund_is_never_classified_as_a_wallet_credit_over_refund(): void
    {
        [, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $fresh = $this->process($this->refundEvent($chargeId, 5_000_000));

        $this->assertSame(0, (int) $fresh->normalized_policy_excess_micro);
    }

    public function test_clearing_the_final_direct_deliverable_dispute_exposure_returns_the_attempt_to_refunded_or_succeeded_per_refund_progress(): void
    {
        [$attempt, , $chargeId] = $this->directDeliverableAttempt(5_000_000);
        $this->process($this->refundEvent($chargeId, 2_000_000));
        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', $chargeId, [
            ['id' => 'txn_dd5w', 'amount' => -1_000_000],
        ], 'dp_fake_dd5'));

        $duringDispute = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Disputed, $duringDispute->state);

        $this->process($this->disputeEvent('charge.dispute.funds_reinstated', $chargeId, [
            ['id' => 'txn_dd5w', 'amount' => -10_000],
            ['id' => 'txn_dd5r', 'amount' => 1_000_000],
        ], 'dp_fake_dd5'));

        $afterReinstatement = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $afterReinstatement->state);
    }

    public function test_two_different_provider_event_ids_reporting_the_same_direct_deliverable_outcome_apply_it_exactly_once(): void
    {
        [, , $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $this->process($this->refundEvent($chargeId, 2_000_000, 'evt_dd6_a'));
        $this->process($this->refundEvent($chargeId, 2_000_000, 'evt_dd6_b'));

        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_a_refunded_direct_deliverable_addon_purchase_remains_historically_completed(): void
    {
        [$attempt, $addonPurchaseId, $chargeId] = $this->directDeliverableAttempt(5_000_000);
        $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);

        $this->process($this->refundEvent($chargeId, 5_000_000));

        $freshPurchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $freshPurchase->status);
    }
}
