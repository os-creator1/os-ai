<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Jobs\Usage\PurgeExpiredWebhookPayloads;
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
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §18 — the widened, existing payment_provider_events
 * table's durable, administrator-visible audit attribution: four distinct,
 * unambiguous amount fields, business/funding-attempt identity, survival
 * across payload purge, and the recentOutcomes() surface's own bounded,
 * deterministically-ordered read.
 */
class PaymentProviderEventDurableAuditTest extends TestCase
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

    private function directDeliverableAttempt(int $priceMicro): array
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $addonKey = 'fixture-audit-'.uniqid();
        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => $addonKey, 'display_name' => 'Fixture Audit Add-on', 'price_micro' => $priceMicro,
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

        return [app(BusinessFundingAttemptRepository::class)->findById($attempt->id), $chargeId];
    }

    private function microToMinorUnitsUsd(int $amountMicro): int
    {
        return intdiv($amountMicro, 10_000);
    }

    private function refundEvent(string $chargeId, int $cumulativeAmountRefundedMicro, ?string $providerEventId = null): PaymentProviderEvent
    {
        return PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => $providerEventId ?? 'evt_'.uniqid(), 'event_type' => 'charge.refunded',
            'provider_object_id' => $chargeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $chargeId, 'payment_intent' => null, 'amount_refunded' => $this->microToMinorUnitsUsd($cumulativeAmountRefundedMicro), 'currency' => 'usd',
            ]]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function process(PaymentProviderEvent $event): PaymentProviderEvent
    {
        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        return PaymentProviderEvent::find($event->id);
    }

    public function test_a_processed_refund_outcome_is_attributed_with_business_and_funding_attempt_identity(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a1');

        $fresh = $this->process($this->refundEvent('ch_fake_a1', 2_000_000));

        $this->assertSame((int) $business->id, (int) $fresh->business_id);
        $this->assertSame((int) $attempt->id, (int) $fresh->funding_attempt_id);
        $this->assertSame('refund_applied', $fresh->normalized_outcome);
        $this->assertNotNull($fresh->normalized_recorded_at);
    }

    public function test_an_ignored_dispute_created_event_is_attributed_with_normalized_status_and_reason(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a2');

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => 'charge.dispute.created',
            'provider_object_id' => 'dp_fake_a2',
            'payload_encrypted' => json_encode(['data' => ['object' => ['id' => 'dp_fake_a2', 'charge' => 'ch_fake_a2', 'payment_intent' => null, 'currency' => 'usd', 'status' => 'warning_needs_response', 'balance_transactions' => []]]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        $fresh = $this->process($event);

        $this->assertSame('dispute_audit_only', $fresh->normalized_outcome);
        $this->assertSame($attempt->state->value, $fresh->normalized_status);
        $this->assertSame('charge.dispute.created', $fresh->normalized_reason);
    }

    public function test_a_direct_deliverable_addon_refund_is_durably_audited_with_the_actual_outcome_delta_despite_a_zero_wallet_delta(): void
    {
        [$attempt, $chargeId] = $this->directDeliverableAttempt(5_000_000);

        $fresh = $this->process($this->refundEvent($chargeId, 2_000_000));

        $this->assertSame(2_000_000, (int) $fresh->normalized_outcome_delta_micro);
        $this->assertSame(0, (int) $fresh->normalized_wallet_delta_micro);
    }

    public function test_normalized_attribution_survives_payload_purge(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a4');
        $fresh = $this->process($this->refundEvent('ch_fake_a4', 2_000_000));

        DB::table('payment_provider_events')->where('id', $fresh->id)->update([
            'completed_at' => now()->subDays(400),
        ]);
        app(PurgeExpiredWebhookPayloads::class)->handle(app(PaymentProviderEventRepository::class));

        $afterPurge = PaymentProviderEvent::find($fresh->id);
        $this->assertNull($afterPurge->payload_encrypted);
        $this->assertSame('refund_applied', $afterPurge->normalized_outcome);
        $this->assertSame(2_000_000, (int) $afterPurge->normalized_outcome_delta_micro);
    }

    public function test_the_provider_events_admin_surface_lists_recent_normalized_outcomes_ordered_by_normalized_recorded_at_then_id(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a5a');
        $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a5b');

        $first = $this->process($this->refundEvent('ch_fake_a5a', 1_000_000));
        $second = $this->process($this->refundEvent('ch_fake_a5b', 1_000_000));

        DB::table('payment_provider_events')->whereIn('id', [$first->id, $second->id])->update(['normalized_recorded_at' => now()]);

        $recent = app(PaymentProviderEventRepository::class)->recentOutcomes(50);
        $ids = $recent->pluck('id')->all();
        $posFirst = array_search($first->id, $ids, true);
        $posSecond = array_search($second->id, $ids, true);

        $this->assertNotFalse($posFirst);
        $this->assertNotFalse($posSecond);
        $this->assertLessThan($posFirst, $posSecond, 'A later (higher id) recorded outcome must sort before an earlier one when timestamps tie.');
    }

    public function test_recent_outcomes_clamps_its_accepted_limit_to_the_locked_maximum_and_minimum_regardless_of_the_requested_value(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        for ($i = 0; $i < 3; $i++) {
            $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a6_'.$i);
            $this->process($this->refundEvent('ch_fake_a6_'.$i, 1_000_000));
        }

        $clampedHigh = app(PaymentProviderEventRepository::class)->recentOutcomes(1_000_000);
        $clampedLow = app(PaymentProviderEventRepository::class)->recentOutcomes(0);

        $this->assertLessThanOrEqual(100, $clampedHigh->count());
        $this->assertGreaterThanOrEqual(1, $clampedLow->count());
    }

    public function test_a_refund_object_event_is_recorded_as_audit_only_with_no_wallet_mutation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $attempt = $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a7');

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => 'refund.created',
            'provider_object_id' => 're_fake_a7',
            'payload_encrypted' => json_encode(['data' => ['object' => ['id' => 're_fake_a7', 'charge' => 'ch_fake_a7', 'payment_intent' => null, 'amount' => 100, 'currency' => 'usd', 'status' => 'succeeded']]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        $fresh = $this->process($event);

        $this->assertSame('ignored', $fresh->state->value);
        $this->assertSame('refund_object_audit_only', $fresh->normalized_outcome);
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_the_administrator_audit_renders_reported_outcome_delta_wallet_delta_and_policy_excess_amounts_exactly_for_a_policy_excess_refund(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 1_000_000, 'ch_fake_a8');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 600_000, 'refundable_paid_available_micro' => 600_000]);

        $fresh = $this->process($this->refundEvent('ch_fake_a8', 1_000_000));

        $this->assertSame(1_000_000, (int) $fresh->normalized_reported_amount_micro);
        $this->assertSame(1_000_000, (int) $fresh->normalized_outcome_delta_micro);
        $this->assertSame(600_000, (int) $fresh->normalized_wallet_delta_micro);
        $this->assertSame(400_000, (int) $fresh->normalized_policy_excess_micro);
        // RFC-005 Remediation #6 second exceptional post-merge implementation
        // correction — normalized_outcome is widened to string(64) specifically
        // so this exact, locked 33-character value round-trips byte-for-byte,
        // never silently truncated by the column.
        $this->assertSame('refund_exceeds_refundable_balance', $fresh->normalized_outcome);
    }

    public function test_a_compliant_partial_refund_records_reported_100_outcome_delta_40_and_wallet_delta_40(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 1_000_000, 'ch_fake_a9');

        $this->process($this->refundEvent('ch_fake_a9', 600_000));
        $fresh = $this->process($this->refundEvent('ch_fake_a9', 1_000_000));

        $this->assertSame(1_000_000, (int) $fresh->normalized_reported_amount_micro);
        $this->assertSame(400_000, (int) $fresh->normalized_outcome_delta_micro);
        $this->assertSame(400_000, (int) $fresh->normalized_wallet_delta_micro);
        $this->assertSame(0, (int) $fresh->normalized_policy_excess_micro);
    }

    public function test_a_replayed_refund_records_reported_100_with_outcome_delta_0_and_wallet_delta_0(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 1_000_000, 'ch_fake_a10');

        $this->process($this->refundEvent('ch_fake_a10', 1_000_000));
        $fresh = $this->process($this->refundEvent('ch_fake_a10', 1_000_000));

        $this->assertSame(1_000_000, (int) $fresh->normalized_reported_amount_micro);
        $this->assertSame(0, (int) $fresh->normalized_outcome_delta_micro);
        $this->assertSame(0, (int) $fresh->normalized_wallet_delta_micro);
    }

    public function test_a_direct_deliverable_refund_records_reported_100_outcome_delta_100_and_wallet_delta_0(): void
    {
        [, $chargeId] = $this->directDeliverableAttempt(1_000_000);

        $fresh = $this->process($this->refundEvent($chargeId, 1_000_000));

        $this->assertSame(1_000_000, (int) $fresh->normalized_reported_amount_micro);
        $this->assertSame(1_000_000, (int) $fresh->normalized_outcome_delta_micro);
        $this->assertSame(0, (int) $fresh->normalized_wallet_delta_micro);
    }

    public function test_a_policy_excess_refund_records_reported_100_outcome_delta_100_wallet_delta_60_and_policy_excess_40(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $this->topUpAttempt($business, $customer->user_id, 1_000_000, 'ch_fake_a12');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 600_000, 'refundable_paid_available_micro' => 600_000]);

        $fresh = $this->process($this->refundEvent('ch_fake_a12', 1_000_000));

        $this->assertSame(1_000_000, (int) $fresh->normalized_reported_amount_micro);
        $this->assertSame(1_000_000, (int) $fresh->normalized_outcome_delta_micro);
        $this->assertSame(600_000, (int) $fresh->normalized_wallet_delta_micro);
        $this->assertSame(400_000, (int) $fresh->normalized_policy_excess_micro);
    }

    /**
     * RFC-005 Remediation #6 §3/§16/§17/§18, third exceptional post-merge
     * implementation correction — both audit-only handlers now apply §3's
     * resolution rule uniformly: conflicting references fail closed with
     * cross_reference_ambiguity, and neither reference resolving fails
     * closed with no_matching_local_record, exactly as the mutating event
     * types already required. Only a uniquely resolved attempt may be
     * marked ignored/audit-only.
     */
    private function disputeLifecycleEvent(string $eventType, ?string $paymentIntent, ?string $charge): PaymentProviderEvent
    {
        $disputeId = 'dp_fake_'.uniqid();

        return PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => $eventType,
            'provider_object_id' => $disputeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $disputeId, 'charge' => $charge, 'payment_intent' => $paymentIntent, 'currency' => 'usd',
                'status' => 'warning_needs_response', 'balance_transactions' => [],
            ]]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function refundObjectEvent(string $eventType, ?string $paymentIntent, ?string $charge): PaymentProviderEvent
    {
        $refundId = 're_fake_'.uniqid();

        return PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => $eventType,
            'provider_object_id' => $refundId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $refundId, 'charge' => $charge, 'payment_intent' => $paymentIntent, 'amount' => 100, 'currency' => 'usd', 'status' => 'succeeded',
            ]]]),
            'payload_hash' => hash('sha256', uniqid()), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    public function test_a_dispute_lifecycle_event_whose_references_resolve_to_different_attempts_fails_closed_with_zero_mutation(): void
    {
        [$customerA, $businessA] = $this->businessWithProviderCustomer();
        $attemptA = $this->topUpAttempt($businessA, $customerA->user_id, 5_000_000, 'ch_fake_dla');
        [$customerB, $businessB] = $this->businessWithProviderCustomer();
        $attemptB = $this->topUpAttempt($businessB, $customerB->user_id, 5_000_000, 'ch_fake_dlb');

        $event = $this->disputeLifecycleEvent(
            'charge.dispute.updated',
            $attemptA->provider_payment_intent_reference,
            $attemptB->provider_charge_reference,
        );

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('cross_reference_ambiguity', $fresh->last_error);
        $this->assertNull($fresh->business_id);
        $this->assertNull($fresh->funding_attempt_id);
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $businessA->id)->value('available_balance_micro'));
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $businessB->id)->value('available_balance_micro'));
    }

    public function test_a_dispute_lifecycle_event_resolving_by_neither_reference_fails_closed_with_zero_mutation(): void
    {
        $event = $this->disputeLifecycleEvent('charge.dispute.closed', 'pi_fake_nowhere_'.uniqid(), 'ch_fake_nowhere_'.uniqid());

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('no_matching_local_record', $fresh->last_error);
        $this->assertNotSame('ignored', $fresh->state->value);
    }

    public function test_a_refund_object_event_whose_references_resolve_to_different_attempts_fails_closed_with_zero_mutation(): void
    {
        [$customerA, $businessA] = $this->businessWithProviderCustomer();
        $attemptA = $this->topUpAttempt($businessA, $customerA->user_id, 5_000_000, 'ch_fake_roa');
        [$customerB, $businessB] = $this->businessWithProviderCustomer();
        $attemptB = $this->topUpAttempt($businessB, $customerB->user_id, 5_000_000, 'ch_fake_rob');

        $event = $this->refundObjectEvent(
            'refund.updated',
            $attemptA->provider_payment_intent_reference,
            $attemptB->provider_charge_reference,
        );

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('cross_reference_ambiguity', $fresh->last_error);
        $this->assertNull($fresh->business_id);
        $this->assertNull($fresh->funding_attempt_id);
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $businessA->id)->value('available_balance_micro'));
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $businessB->id)->value('available_balance_micro'));
    }

    public function test_a_refund_object_event_resolving_by_neither_reference_fails_closed_with_zero_mutation(): void
    {
        $event = $this->refundObjectEvent('refund.created', 'pi_fake_nowhere_'.uniqid(), 'ch_fake_nowhere_'.uniqid());

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('no_matching_local_record', $fresh->last_error);
        $this->assertNotSame('ignored', $fresh->state->value);
    }
}
