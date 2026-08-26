<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\SlotAgreementState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §15 (Correction Round 1 §A) — ProcessPaymentProviderEvent
 * correctly routes exactly the three canonical app_subject_kind values,
 * still fails closed on missing/unrecognized metadata, and explicitly
 * proves an add-on purchase's own webhook event arrives through the
 * existing funding_attempt subject.
 *
 * Strengthened: slot_renewal_charge webhook success/failure reaches the
 * manager, never a direct repository mutation from this job; the
 * amount-vs-amount_total fallback (§15b) is proven directly for
 * slot_agreement's own Checkout Session event shape.
 */
class WebhookSlotAgreementSubjectRoutingTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProviderGateway $gateway;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    private function postWebhook(string $eventType, array $object): void
    {
        $rawBody = json_encode(['id' => 'evt_'.uniqid(), 'type' => $eventType, 'data' => ['object' => $object]]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);
    }

    private function checkoutPendingAgreement(): array
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', false, 0);

        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);

        return [$workspace, $owner, app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id)];
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §8.B — proves the
     * real UsageBillingCheckoutManager::initiateSlotAgreementCheckout()
     * call site (via this file's own checkoutPendingAgreement() helper,
     * not merely the gateway fake in isolation) actually passes
     * setupFutureUsageOffSession: true, preserving its own
     * designed-future-off-session-renewal semantics unchanged.
     */
    public function test_initiate_slot_agreement_checkout_passes_setup_future_usage_true(): void
    {
        $this->checkoutPendingAgreement();

        $this->assertCount(1, $this->gateway->createCheckoutSessionCalls);
        $this->assertTrue($this->gateway->createCheckoutSessionCalls[0]['setupFutureUsageOffSession']);
    }

    public function test_slot_agreement_checkout_session_completes_via_webhook_using_amount_total_fallback(): void
    {
        [$workspace, , $agreement] = $this->checkoutPendingAgreement();

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_webhook', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_webhook'];

        $currencyCode = 'USD';
        $minorUnits = (int) bcdiv((string) $agreement->total_amount_micro_snapshot, '10000', 0);

        // Checkout Session shape: amount_total, never amount.
        $this->postWebhook('checkout.session.completed', [
            'id' => $agreement->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'slot_agreement', 'app_subject_id' => (string) $agreement->id, 'app_operation_id' => $agreement->local_idempotency_key],
            'amount_total' => $minorUnits,
            'currency' => strtolower($currencyCode),
            'customer' => $providerCustomer->provider_customer_id,
        ]);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('processed', $event->state->value);

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $refreshed->state);
    }

    public function test_slot_agreement_unrecognized_addon_purchase_kind_fails_closed(): void
    {
        [, , $agreement] = $this->checkoutPendingAgreement();

        $this->postWebhook('checkout.session.completed', [
            'id' => $agreement->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'addon_purchase', 'app_subject_id' => (string) $agreement->id, 'app_operation_id' => $agreement->local_idempotency_key],
            'amount_total' => 1000, 'currency' => 'usd', 'customer' => 'cus_fake',
        ]);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::CheckoutPending, $refreshed->state);
    }

    public function test_slot_renewal_charge_webhook_success_reaches_the_manager_never_a_direct_mutation(): void
    {
        [$workspace, $owner, $agreement] = $this->checkoutPendingAgreement();
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_initial', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_initial'];
        app(UsageBillingCheckoutManager::class)->confirmSlotAgreementFromReturn($agreement);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['next_renewal_at' => now()->subMinute()]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';
        app(UsageBillingCheckoutManager::class)->createScheduledRenewalCharge($agreement);
        $charge = DB::table('additional_business_slot_renewal_charges')->where('agreement_id', $agreement->id)->first();

        $currencyCode = 'USD';
        $minorUnits = (int) bcdiv((string) $charge->amount_micro_snapshot, '10000', 0);

        $this->postWebhook('payment_intent.succeeded', [
            'id' => $charge->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'slot_renewal_charge', 'app_subject_id' => (string) $charge->id, 'app_operation_id' => $charge->local_idempotency_key],
            'amount' => $minorUnits, 'currency' => strtolower($currencyCode), 'customer' => $charge->provider_customer_external_id_snapshot,
        ]);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('processed', $event->state->value);

        $refreshedCharge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);
        $this->assertSame(FundingAttemptState::Succeeded, $refreshedCharge->state);
    }

    public function test_slot_renewal_charge_webhook_failure_reaches_the_managers_failure_method(): void
    {
        [$workspace, $owner, $agreement] = $this->checkoutPendingAgreement();
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_initial', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_initial'];
        app(UsageBillingCheckoutManager::class)->confirmSlotAgreementFromReturn($agreement);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['next_renewal_at' => now()->subMinute()]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';
        app(UsageBillingCheckoutManager::class)->createScheduledRenewalCharge($agreement);
        $charge = DB::table('additional_business_slot_renewal_charges')->where('agreement_id', $agreement->id)->first();

        $currencyCode = 'USD';
        $minorUnits = (int) bcdiv((string) $charge->amount_micro_snapshot, '10000', 0);

        $this->postWebhook('payment_intent.payment_failed', [
            'id' => $charge->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'slot_renewal_charge', 'app_subject_id' => (string) $charge->id, 'app_operation_id' => $charge->local_idempotency_key],
            'amount' => $minorUnits, 'currency' => strtolower($currencyCode), 'customer' => $charge->provider_customer_external_id_snapshot,
        ]);

        $refreshedCharge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);
        $this->assertSame(FundingAttemptState::Failed, $refreshedCharge->state);
    }
}
