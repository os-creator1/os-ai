<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\FundingAttemptNotResumableException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
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
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_platform_administrator_can_resume_a_stuck_attempt(): void
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
        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $stuck = $checkoutManager->initiateTopUp($business, $customer->user_id, 1_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($stuck->fundingAttemptId);
        $this->assertSame(FundingAttemptState::RequiresAction, $attempt->state);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $resumed = $checkoutManager->retryFundingAttemptAsAdministrator($attempt, (int) $admin->id, 'Customer confirmed via support call.');

        $this->assertSame(FundingAttemptState::Succeeded, $resumed->state);
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
