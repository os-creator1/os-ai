<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\FundingAttemptNotResumableException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
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
use App\Jobs\Usage\SendReceiptNotification;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §11/§17/§25 item 81 — the payer-consent split for
 * top-up initiation, including the narrowed platform-administrator
 * posture (resume-only, never origination).
 */
class FundingAttemptPayerConsentTest extends TestCase
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

    public function test_workspace_owner_can_initiate_a_top_up_when_workspace_pays(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 1_000_000);

        // no_provider_customer, not an authorization exception — consent
        // itself was granted, provider setup simply doesn't exist yet.
        $this->assertSame('no_provider_customer', $result->denialReason);
    }

    public function test_direct_business_owner_cannot_initiate_a_top_up_when_workspace_pays(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $directOwner = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $directOwner->user_id, 1_000_000);
    }

    public function test_direct_business_owner_can_initiate_a_top_up_when_business_pays(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $directOwner = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, $directOwner->user_id, 'Test.');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $directOwner->user_id, 1_000_000);

        $this->assertSame('no_provider_customer', $result->denialReason);
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §17 — corrected:
     * a stuck ManualTopUp attempt is now Checkout-Session-backed
     * (provider_pending, never requires_action via paymentIntentOutcomes,
     * which no longer applies once initiateTopUp() never calls
     * createOffSessionPaymentIntent()), and needs no pre-saved instrument.
     * retryFundingAttemptAsAdministrator()'s new Checkout-Session branch
     * is exercised by registering a verified complete/paid Session.
     */
    public function test_platform_administrator_can_resume_a_stuck_attempt(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $stuck = $checkoutManager->initiateTopUp($business, $customer->user_id, 1_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($stuck->fundingAttemptId);
        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->state);

        $paymentMethodId = 'pm_fake_admin_resume';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkoutManager->expectedMinorUnitsFor($attempt),
            $checkoutManager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_admin_resume',
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_admin_resume',
            'ch_fake_admin_resume',
        ));

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $resumed = $checkoutManager->retryFundingAttemptAsAdministrator($attempt, (int) $admin->id, 'Customer confirmed via support call.');

        $this->assertSame(FundingAttemptState::Succeeded, $resumed->state);
    }

    private function businessWithAttachedInstrument(): array
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

        return [$customer, $business];
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §13 — AutoRecharge
     * must not regress: unchanged off-session PaymentIntent path, unchanged
     * pre-saved-instrument requirement, unchanged creation-time display
     * snapshot.
     */
    public function test_auto_recharge_still_creates_an_off_session_payment_intent_and_snapshots_the_instrument_at_creation(): void
    {
        Queue::fake();

        [$customer, $business] = $this->businessWithAttachedInstrument();

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);

        $this->assertSame(FundingAttemptState::Succeeded, $result->state);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertStringStartsWith('pi_fake_', (string) $attempt->provider_session_or_intent_reference);
        $this->assertStringContainsString('••••', $attempt->payment_method_display_snapshot);
        $this->assertNotSame('Pending Checkout', $attempt->payment_method_display_snapshot);
        $this->assertEmpty($this->gateway->createCheckoutSessionCalls, 'AutoRecharge must never create a Checkout Session.');

        // Receipt Boundary Correction Contract §3 — the synchronous
        // AutoRecharge success path is one of the five financial-success
        // entry points that must dispatch SendReceiptNotification exactly
        // once, mechanically, via creditFromFunding() itself.
        Queue::assertPushed(SendReceiptNotification::class, 1);
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §12/§13 —
     * AutoRecharge's own webhook confirmation gains no new provider call:
     * proven indirectly by confirming success is reached without any
     * Checkout Session ever being registered/created for this
     * PaymentIntent-backed attempt (had the code taken the Checkout
     * branch, the unknown-Session fallback's zero amount would have
     * failed verification and confirmation would never have succeeded).
     */
    public function test_auto_recharge_webhook_confirmation_performs_no_new_provider_call(): void
    {
        Queue::fake();

        [$customer, $business] = $this->businessWithAttachedInstrument();
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::RequiresAction, $attempt->state);

        $event = \App\Models\PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromWebhook($attempt, $event);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);
        $this->assertEmpty($this->gateway->createCheckoutSessionCalls);

        // Receipt Boundary Correction Contract §3 — the webhook
        // confirmation entry point dispatches SendReceiptNotification
        // exactly once too, mechanically, via creditFromFunding() itself.
        Queue::assertPushed(SendReceiptNotification::class, 1);
    }

    /**
     * Receipt Boundary Correction Contract §10 item 3 — the
     * administrator/reconciliation AutoRecharge entry point, not
     * exercised by test_platform_administrator_can_resume_a_stuck_attempt
     * above (which is ManualTopUp-scoped), also dispatches the receipt
     * job — using the Fake's own deterministic retrievePaymentIntent()
     * default, no explicit registration needed.
     */
    public function test_platform_administrator_resuming_a_stuck_auto_recharge_attempt_dispatches_the_receipt_job(): void
    {
        Queue::fake();

        [$customer, $business] = $this->businessWithAttachedInstrument();
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::RequiresAction, $attempt->state);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $resumed = app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($attempt, (int) $admin->id, 'Customer confirmed via support call.');

        $this->assertSame(FundingAttemptState::Succeeded, $resumed->state);
        Queue::assertPushed(SendReceiptNotification::class, 1);
    }

    public function test_platform_administrator_cannot_originate_a_fresh_top_up_via_checkout_session(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        // initiateTopUp() itself has no administrator-override branch of
        // any kind, Checkout Session or otherwise — the provider-object
        // correction introduces no new origination authority.
        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(UsageBillingCheckoutManager::class)->initiateTopUp($business, (int) $admin->id, 1_000_000);
    }

    public function test_platform_administrator_cannot_enable_auto_recharge_under_any_payer_type(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        // Payer type: Workspace — businessWithAttachedInstrument()'s own
        // default. The admin is neither the workspace owner nor the
        // business's own customer, so must be denied before any state
        // mutation — never bypassed by an admin-role special case.
        $deniedUnderWorkspace = false;
        try {
            app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '3000000', null, (int) $admin->id);
        } catch (UnauthorizedUsageBillingManagementException $e) {
            $deniedUnderWorkspace = true;
        }
        $this->assertTrue($deniedUnderWorkspace, 'Expected denial under PayerType::Workspace.');
        $walletAfterWorkspaceAttempt = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertFalse((bool) $walletAfterWorkspaceAttempt->auto_recharge_enabled);

        // Payer type: Business (direct) — switch the payer, then confirm
        // the identical denial under this payer_type too, exactly as the
        // requirement's own "under any payer_type" wording demands.
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, (int) $customer->user_id, 'Switch to direct payer.');

        $deniedUnderBusiness = false;
        try {
            app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '3000000', null, (int) $admin->id);
        } catch (UnauthorizedUsageBillingManagementException $e) {
            $deniedUnderBusiness = true;
        }
        $this->assertTrue($deniedUnderBusiness, 'Expected denial under PayerType::Business.');
        $walletAfterBusinessAttempt = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertFalse((bool) $walletAfterBusinessAttempt->auto_recharge_enabled);
    }

    public function test_completing_a_top_up_never_enables_auto_recharge(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $result = $checkoutManager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_no_auto_recharge';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkoutManager->expectedMinorUnitsFor($attempt),
            $checkoutManager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_no_auto_recharge',
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_no_auto_recharge',
            'ch_fake_no_auto_recharge',
        ));

        $checkoutManager->confirmAttemptFromReturn($attempt);

        $wallet = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);
    }

    public function test_a_checkout_session_event_cannot_confirm_an_auto_recharge_attempt(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $rawBody = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $attempt->provider_session_or_intent_reference,
                'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
                'amount_total' => $manager->expectedMinorUnitsFor($attempt),
                'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
                'customer' => $attempt->provider_customer_external_id_snapshot,
            ]],
        ]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);

        $event = \App\Models\PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('failed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::RequiresAction, $freshAttempt->state);
    }

    public function test_platform_administrator_cannot_resume_an_attempt_with_no_provider_reference(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        $wallet = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $providerCustomer = app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $attempt = app(BusinessFundingAttemptRepository::class)->create([
            'business_id' => $business->id,
            'wallet_id' => $wallet->id,
            'purpose' => 'manual_top_up',
            'payer_type_snapshot' => 'workspace',
            'provider_customer_external_id_snapshot' => 'cus_x',
            'provider_customer_id' => $providerCustomer->id,
            'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/26',
            'expected_currency_id' => $wallet->currency_id,
            'expected_amount_micro' => 1000000,
            'local_idempotency_key' => 'idem-orphan',
            'state' => 'failed',
        ]);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->expectException(FundingAttemptNotResumableException::class);
        app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($attempt, (int) $admin->id, 'Reason.');
    }
}
