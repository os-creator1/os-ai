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
 * RFC-005 M3 contract §13 steps 7-11/§25 item 89 — missing/malformed/
 * unknown/ambiguous/mismatched metadata produces zero mutation, marks the
 * event failed, routes to reconciliation.
 */
class WebhookMetadataSpoofMismatchTest extends TestCase
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
     * A pending (requires_action) attempt with a real, valid provider
     * reference — the target for the webhook confirmation this test sends.
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
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        return [$business, $attempt];
    }

    private function postWebhook(array $objectOverrides): void
    {
        $rawBody = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => array_merge([
                'id' => 'pi_placeholder',
                'metadata' => new \stdClass(),
            ], $objectOverrides)],
        ]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);
    }

    public function test_missing_metadata_causes_zero_mutation_and_marks_the_event_failed(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();
        $walletBefore = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);

        $this->postWebhook(['id' => $attempt->provider_session_or_intent_reference]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('requires_action', $freshAttempt->state->value);

        $walletAfter = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame((string) $walletBefore->available_balance_micro, (string) $walletAfter->available_balance_micro);
    }

    public function test_unrecognized_subject_kind_causes_zero_mutation(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();

        $this->postWebhook([
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'slot_agreement', 'app_subject_id' => (string) $attempt->id],
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);
    }

    public function test_nonexistent_subject_id_causes_zero_mutation(): void
    {
        $this->createPendingAttempt();

        $this->postWebhook([
            'id' => 'pi_placeholder',
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => '999999999'],
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);
    }

    public function test_mismatched_provider_object_id_causes_zero_mutation(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();

        $this->postWebhook([
            'id' => 'pi_completely_different',
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id],
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('requires_action', $freshAttempt->state->value);
    }

    public function test_mismatched_operation_id_causes_zero_mutation(): void
    {
        [$business, $attempt] = $this->createPendingAttempt();

        $this->postWebhook([
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => 'wrong-idempotency-key'],
        ]);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);
    }
}
