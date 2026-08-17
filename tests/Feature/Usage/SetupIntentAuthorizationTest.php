<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §10/§17 — the full payer-consent matrix for
 * SetupIntent creation: gated identically to a charge-causing action, no
 * platform-administrator override for origination.
 */
class SetupIntentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());
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

    public function test_workspace_owner_can_create_a_setup_intent_when_workspace_pays(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $result = app(PaymentInstrumentManager::class)->createSetupIntent($business, $customer->user_id);

        $this->assertNotNull($result->clientSecret);
    }

    public function test_direct_business_owner_cannot_create_a_setup_intent_when_workspace_pays(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);

        $directOwner = $this->createCustomer();
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(PaymentInstrumentManager::class)->createSetupIntent($business, $directOwner->user_id);
    }

    public function test_direct_business_owner_can_create_a_setup_intent_when_business_pays(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);

        $directOwner = $this->createCustomer();
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, $directOwner->user_id, 'Test.');

        $result = app(PaymentInstrumentManager::class)->createSetupIntent($business, $directOwner->user_id);

        $this->assertNotNull($result->clientSecret);
    }

    public function test_workspace_owner_cannot_create_a_setup_intent_when_business_pays(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);

        $directOwner = $this->createCustomer();
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, $directOwner->user_id, 'Test.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(PaymentInstrumentManager::class)->createSetupIntent($business, $ownerCustomer->user_id);
    }

    public function test_unrelated_actor_is_denied(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($ownerCustomer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');

        $unrelated = $this->createCustomer();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(PaymentInstrumentManager::class)->createSetupIntent($business, $unrelated->user_id);
    }
}
