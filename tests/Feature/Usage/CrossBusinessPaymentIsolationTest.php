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
use App\Library\Usage\UsageBillingPresenter;
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
 * RFC-005 M3 contract §9/§25 item 99 — Business A's instruments, funding
 * history, and provider-event status are never visible from Business B's
 * own dashboard request or repository lookup, even within the same
 * Workspace, mirroring CrossBusinessBillingIsolationTest's own M2 pattern
 * exactly.
 */
class CrossBusinessPaymentIsolationTest extends TestCase
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

    public function test_a_business_owned_instrument_is_never_visible_from_a_different_businesss_dashboard(): void
    {
        $ownerA = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerA->user);
        $businessA = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerA, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(BillingProfileManager::class)->changePayer($businessA, PayerType::Business, $ownerA->user_id, 'Test.');

        $ownerB = $this->createCustomer();
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerB, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);
        app(BillingProfileManager::class)->changePayer($businessB, PayerType::Business, $ownerB->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($businessA, $ownerA->user_id);
        $providerCustomerA = app(PaymentProviderCustomerRepository::class)->findActiveByBusinessId((int) $businessA->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomerA->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($businessA, $ownerA->user_id, $setupIntent->providerSetupIntentId);

        $this->assertNull(app(PaymentProviderCustomerRepository::class)->findActiveByBusinessId((int) $businessB->id));

        $viewModelB = app(UsageBillingPresenter::class)->buildDashboardViewModel($businessB);
        $this->assertNull($viewModelB->paymentMethod, 'Business B must never see Business A\'s payment method.');

        $viewModelA = app(UsageBillingPresenter::class)->buildDashboardViewModel($businessA);
        $this->assertNotNull($viewModelA->paymentMethod);
    }

    public function test_funding_history_repository_lookup_is_business_scoped(): void
    {
        $ownerA = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerA->user);
        $businessA = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerA, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(BillingProfileManager::class)->changePayer($businessA, PayerType::Workspace, $ownerA->user_id, 'Test.');

        $ownerB = $this->createCustomer();
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerB, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($businessA, $ownerA->user_id);
        $providerCustomerA = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomerA->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($businessA, $ownerA->user_id, $setupIntent->providerSetupIntentId);

        app(UsageBillingCheckoutManager::class)->initiateTopUp($businessA, $ownerA->user_id, 2_000_000);

        $attemptsForA = app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $businessA->id)->count();
        $attemptsForB = app(BusinessFundingAttemptRepository::class)->query()->where('business_id', $businessB->id)->count();
        $this->assertSame(1, $attemptsForA);
        $this->assertSame(0, $attemptsForB);

        $viewModelB = app(UsageBillingPresenter::class)->buildDashboardViewModel($businessB);
        $this->assertTrue($viewModelB->fundingHistory->isEmpty(), 'Business B\'s dashboard must never render Business A\'s funding history.');
    }
}
