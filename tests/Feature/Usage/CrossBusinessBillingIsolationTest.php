<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageBillingPresenter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §7/§6.F — Business A's billing contact, payer,
 * feature limits, and audit history are never visible from Business B's
 * own repository lookup or dashboard view model, even within the same
 * Workspace.
 */
class CrossBusinessBillingIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_billing_contact_repository_lookup_is_business_scoped(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $businessB = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        app(BillingProfileManager::class)->updateBillingContact($businessA, null, 'Jane Doe', 'jane@example.test', true, (int) $businessA->customer_id);

        $this->assertNull(app(BusinessBillingContactRepository::class)->findByBusinessId((int) $businessB->id));
        $this->assertNotNull(app(BusinessBillingContactRepository::class)->findByBusinessId((int) $businessA->id));
    }

    public function test_payer_assignment_repository_lookup_is_business_scoped(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $businessB = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness((int) $businessA->id);
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness((int) $businessB->id);

        $assignmentA = app(BusinessPayerAssignmentRepository::class)->findByBusinessId((int) $businessA->id);
        $assignmentB = app(BusinessPayerAssignmentRepository::class)->findByBusinessId((int) $businessB->id);

        $this->assertNotSame($assignmentA->id, $assignmentB->id);
    }

    public function test_feature_limits_are_business_scoped(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $businessB = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);

        $businessA->loadMissing('workspace');
        app(UsageWalletManager::class)->setFeatureLimit($businessA, 'crm', '1000000', (int) $businessA->workspace->owner_user_id, 'A only.');

        $this->assertEmpty(app(BusinessFeatureUsageLimitRepository::class)->forBusiness((int) $businessB->id));
        $this->assertNotEmpty(app(BusinessFeatureUsageLimitRepository::class)->forBusiness((int) $businessA->id));
    }

    public function test_dashboard_view_model_never_leaks_another_businesss_data(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $businessB = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);
        app(BillingProfileManager::class)->updateBillingContact($businessA, null, 'Jane Doe', 'jane@example.test', true, (int) $businessA->customer_id);

        $viewModelB = app(UsageBillingPresenter::class)->buildDashboardViewModel($businessB);

        $this->assertNull($viewModelB->billingContact);
        $this->assertSame($businessB->name, $viewModelB->business['name']);
    }
}
