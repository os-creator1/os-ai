<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §13 step 10/§25 item 90 — a verified Stripe object
 * whose amount/currency/customer disagrees with the loaded local record's
 * own frozen expectations produces zero mutation.
 */
class WebhookAmountCurrencyCustomerMismatchTest extends TestCase
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
     * RFC-005 Funding Provider-Flow Correction Contract §17 — re-scoped
     * from initiateTopUp() to initiateAutoRecharge(): AutoRecharge is the
     * only purpose remaining genuinely PaymentIntent-shaped, so the
     * existing payment_intent.succeeded/top-level-"amount" webhook
     * fixtures below stay accurate rather than becoming a stale hybrid.
     * The Checkout-Session-shaped sibling of this exact mismatch-rejection
     * invariant (amount_total, checkout.session.completed) is separate,
     * new coverage in TopUpStateMachineTest.php.
     */
    private function createPendingAttempt(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        // 5,000,000 micro-units -> 500 minor units (USD, 2-decimal exponent).
        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        return [$business, $attempt];
    }

    private function postWebhook(array $object): void
    {
        $rawBody = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => $object],
        ]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);
    }

    private function matchingMetadata($attempt): array
    {
        return ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key];
    }

    public function test_amount_mismatch_produces_zero_mutation(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();
        $walletBefore = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);

        $this->postWebhook([
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => $this->matchingMetadata($attempt),
            'amount' => 999, // wrong — expected 500
            'currency' => 'usd',
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);
        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('requires_action', $freshAttempt->state->value);

        $walletAfter = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame((string) $walletBefore->available_balance_micro, (string) $walletAfter->available_balance_micro);
    }

    public function test_currency_mismatch_produces_zero_mutation(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();

        $this->postWebhook([
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => $this->matchingMetadata($attempt),
            'amount' => 500,
            'currency' => 'eur', // wrong — expected usd
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);
    }

    public function test_customer_mismatch_produces_zero_mutation(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();

        $this->postWebhook([
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => $this->matchingMetadata($attempt),
            'amount' => 500,
            'currency' => 'usd',
            'customer' => 'cus_completely_unrelated',
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);
    }

    public function test_a_fully_matching_webhook_succeeds_and_credits_the_wallet_exactly_once(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();
        $walletBefore = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);

        $this->postWebhook([
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => $this->matchingMetadata($attempt),
            'amount' => 500,
            'currency' => 'usd',
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('processed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('succeeded', $freshAttempt->state->value);

        $walletAfter = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame(
            (string) ((int) $walletBefore->available_balance_micro + 5_000_000),
            (string) $walletAfter->available_balance_micro,
        );
    }
}
