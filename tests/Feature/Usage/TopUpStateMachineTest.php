<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
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
use App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §11/§25 item 80 — every top-up state transition,
 * append-only transition-row creation, idempotent repeat-commit no-op.
 *
 * RFC-005 Funding Provider-Flow Correction Contract §17 — corrected for
 * the Checkout-Session-backed ManualTopUp lifecycle: a Checkout Session
 * never synchronously succeeds at creation the way an off-session
 * PaymentIntent could, so every "successful" scenario below now drives an
 * explicit confirmAttemptFromReturn()/webhook step after initiateTopUp().
 */
class TopUpStateMachineTest extends TestCase
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
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §9 — a
     * ManualTopUp attempt still requires a resolved provider customer
     * (Option 1, unchanged), but never a pre-saved default instrument —
     * this fixture deliberately establishes only the former.
     *
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

    /**
     * Registers a fully deterministic, complete/paid CheckoutSessionResult
     * plus a matching PaymentMethodResult for a given attempt's own
     * persisted Session id — the exact registration technique the merged
     * contract's own §16.A locks for cross-process determinism, reused
     * here for single-process precision whenever a test cares about the
     * exact finalized payment_method_display_snapshot value (the fake
     * gateway's own default fallback returns a PaymentMethodResult whose
     * providerCustomerId is the fixed string 'cus_fake_unknown', which
     * never matches a real provider-customer id — deliberately triggering
     * the contradictory-customer skip rule unless overridden here).
     */
    private function registerVerifiedCheckoutOutcome(BusinessFundingAttempt $attempt, ?string $paymentMethodCustomerId = null): void
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_verified_'.uniqid();

        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId,
            $paymentMethodCustomerId ?? $attempt->provider_customer_external_id_snapshot,
            'card', 'visa', '4242', 12, 2030,
        ));

        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_verified_'.uniqid(),
            $paymentMethodId,
        ));
    }

    private function postCheckoutWebhook(string $eventType, array $object): void
    {
        $rawBody = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => $eventType,
            'data' => ['object' => $object],
        ]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);
    }

    public function test_successful_top_up_creates_a_checkout_session_reaching_provider_pending_then_succeeded_on_confirmation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::ProviderPending, $result->state);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->state);

        $confirmed = app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);
        $this->assertSame(FundingAttemptState::Succeeded, $confirmed->state);

        $transitions = app(BusinessFundingAttemptTransitionRepository::class)->query()
            ->where('funding_attempt_id', $result->fundingAttemptId)->orderBy('id')->pluck('to_state')
            ->map(fn ($s) => $s->value)->all();
        $this->assertSame(['created', 'provider_pending', 'succeeded'], $transitions);
    }

    public function test_no_provider_customer_denies_the_attempt(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('no_provider_customer', $result->denialReason);
        $this->assertSame(0, $result->fundingAttemptId);
    }

    public function test_manual_top_up_succeeds_without_a_pre_saved_default_instrument(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $default = app(BusinessPaymentInstrumentRepository::class)->findDefaultForProviderCustomer((int) $providerCustomer->id);
        $this->assertNull($default, 'Fixture assumption: no instrument exists yet.');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::ProviderPending, $result->state);
        $this->assertNull($result->denialReason);
    }

    public function test_repeat_commit_on_an_already_succeeded_attempt_is_idempotent(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $checkoutManager = app(UsageBillingCheckoutManager::class);

        $result = $checkoutManager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $checkoutManager->confirmAttemptFromReturn($attempt);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        // A repeat confirmAttemptFromReturn() on an already-succeeded
        // attempt must not credit the wallet a second time.
        $second = $checkoutManager->confirmAttemptFromReturn($attempt);

        $this->assertSame(FundingAttemptState::Succeeded, $second->state);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro);
    }

    public function test_manual_top_up_creates_a_checkout_session_not_a_payment_intent(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertStringStartsWith('cs_fake_', (string) $attempt->provider_session_or_intent_reference);
        $this->assertCount(1, $this->gateway->createCheckoutSessionCalls);
    }

    public function test_initiate_top_up_returns_a_hosted_redirect_url(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertNotNull($result->redirectUrl);
        $this->assertStringStartsWith('https://checkout.fake.stripe.test/', $result->redirectUrl);
    }

    public function test_confirm_from_return_never_trusts_the_query_string_alone(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        // Simulates a forged/optimistic "success" query string: the fake's
        // own authoritative retrieval still reports the Session as open.
        $this->gateway->checkoutSessionOutcomes[$attempt->local_idempotency_key] = ['status' => 'open', 'paymentStatus' => 'unpaid'];

        $confirmed = app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $this->assertSame(FundingAttemptState::ProviderPending, $confirmed->state);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('0', (string) $wallet->available_balance_micro);
    }

    public function test_checkout_backed_attempt_starts_with_the_pending_checkout_sentinel_and_finalizes_it_on_confirmation(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame('Pending Checkout', $attempt->payment_method_display_snapshot);

        $this->registerVerifiedCheckoutOutcome($attempt);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $confirmedAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertNotSame('Pending Checkout', $confirmedAttempt->payment_method_display_snapshot);
        $this->assertStringContainsString('••••', $confirmedAttempt->payment_method_display_snapshot);
    }

    public function test_failed_or_expired_checkout_never_finalizes_a_payment_method_display_snapshot(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->gateway->checkoutSessionOutcomes[$attempt->local_idempotency_key] = ['status' => 'expired', 'paymentStatus' => 'unpaid'];

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('Pending Checkout', $freshAttempt->payment_method_display_snapshot);
        $this->assertNotSame(FundingAttemptState::Succeeded, $freshAttempt->state);
    }

    public function test_checkout_session_completed_webhook_confirms_a_manual_top_up(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->postCheckoutWebhook('checkout.session.completed', [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount_total' => $manager->expectedMinorUnitsFor($attempt),
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('processed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro);
    }

    public function test_checkout_session_expired_marks_the_attempt_failed(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->postCheckoutWebhook('checkout.session.expired', [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount_total' => $manager->expectedMinorUnitsFor($attempt),
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Failed, $freshAttempt->state);
    }

    public function test_checkout_amount_total_is_validated_against_the_expected_amount(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->postCheckoutWebhook('checkout.session.completed', [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount_total' => 999,
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttempt->state);
    }

    public function test_wrong_amount_currency_customer_object_or_operation_is_rejected_for_a_checkout_backed_event(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $goodObject = [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount_total' => $manager->expectedMinorUnitsFor($attempt),
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ];

        $mismatches = [
            'amount' => array_merge($goodObject, ['amount_total' => 1]),
            'currency' => array_merge($goodObject, ['currency' => 'eur']),
            'customer' => array_merge($goodObject, ['customer' => 'cus_completely_unrelated']),
            'object' => array_merge($goodObject, ['id' => 'cs_completely_different']),
            'operation' => array_merge($goodObject, ['metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => 'wrong-idempotency-key']]),
        ];

        foreach ($mismatches as $label => $object) {
            $this->postCheckoutWebhook('checkout.session.completed', $object);

            $event = PaymentProviderEvent::query()->latest('id')->first();
            $this->assertSame('failed', $event->state->value, "Mismatch [{$label}] must fail the event.");
        }

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttempt->state, 'None of the mismatches may have mutated the attempt.');
    }

    public function test_a_payment_intent_event_cannot_confirm_a_manual_top_up_attempt(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        // A PaymentIntent-shaped event (top-level "amount", never
        // "amount_total") for this Checkout-backed attempt must never
        // confirm it, even with an otherwise-matching amount.
        $this->postCheckoutWebhook('payment_intent.succeeded', [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount' => $manager->expectedMinorUnitsFor($attempt),
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttempt->state);
    }

    public function test_create_checkout_session_records_setup_future_usage_false_for_manual_top_up(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertCount(1, $this->gateway->createCheckoutSessionCalls);
        $this->assertFalse($this->gateway->createCheckoutSessionCalls[0]['setupFutureUsageOffSession']);
    }

    public function test_completing_a_checkout_backed_top_up_never_creates_or_changes_a_reusable_instrument(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $instrumentRepository = app(BusinessPaymentInstrumentRepository::class);

        $countBefore = $instrumentRepository->query()->where('provider_customer_id', $providerCustomer->id)->count();
        $this->assertSame(0, $countBefore);
        $this->assertNull($instrumentRepository->findDefaultForProviderCustomer((int) $providerCustomer->id));

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $countAfter = $instrumentRepository->query()->where('provider_customer_id', $providerCustomer->id)->count();
        $this->assertSame(0, $countAfter, 'A one-time Checkout payment must never insert a reusable instrument row.');
        $this->assertNull($instrumentRepository->findDefaultForProviderCustomer((int) $providerCustomer->id), 'No default instrument may be established as a side effect.');

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertNotSame('Pending Checkout', $freshAttempt->payment_method_display_snapshot, 'The display snapshot must still be finalized with truthful safe display metadata.');

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled, 'Completing a top-up must never enable auto-recharge.');
    }

    public function test_a_paymentmethod_with_no_customer_attachment_still_finalizes_the_display_snapshot(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->registerVerifiedCheckoutOutcome($attempt, paymentMethodCustomerId: '');

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);
        $this->assertNotSame('Pending Checkout', $freshAttempt->payment_method_display_snapshot);
    }

    public function test_a_paymentmethod_with_a_contradictory_customer_skips_display_finalization_but_still_credits(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->registerVerifiedCheckoutOutcome($attempt, paymentMethodCustomerId: 'cus_completely_unrelated');

        $confirmed = app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $this->assertSame(FundingAttemptState::Succeeded, $confirmed->state, 'A contradictory PaymentMethod customer must not deny an independently-verified successful payment.');

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('Pending Checkout', $freshAttempt->payment_method_display_snapshot, 'Display finalization must be skipped, never fabricated from contradictory evidence.');

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro);
    }
}
