<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.E — default payer resolution: Core/Growth ->
 * workspace; Agency -> business; unassigned Workspace -> workspace.
 * Resolved exclusively via EntitlementManager::getWorkspaceEntitlementSummary()
 * — never a raw workspace_plan_catalog/workspace_plan_assignments query.
 */
class PayerAssignmentDefaultByPlanTierTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createAdminUserId(): int
    {
        return \App\Models\User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    public function test_core_tier_defaults_to_workspace_pays(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        app(EntitlementManager::class)->assignFirstPlan($business->workspace, WorkspacePlanTier::Core, $this->createAdminUserId(), 'Fixture.', true, 0);

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
    }

    public function test_growth_tier_defaults_to_workspace_pays(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        app(EntitlementManager::class)->assignFirstPlan($business->workspace, WorkspacePlanTier::Growth, $this->createAdminUserId(), 'Fixture.', true, 0);

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
    }

    public function test_agency_tier_defaults_to_business_pays(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        app(EntitlementManager::class)->assignFirstPlan($business->workspace, WorkspacePlanTier::Agency, $this->createAdminUserId(), 'Fixture.', true, 0);

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'business']);
    }

    public function test_unassigned_workspace_defaults_to_workspace_pays(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        // Deliberately no assignFirstPlan() call — the Workspace has no
        // plan assignment at all.
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
    }

    public function test_no_raw_plan_table_query_in_billing_profile_manager_source(): void
    {
        $source = file_get_contents(app_path('Library/Usage/BillingProfileManager.php'));

        $this->assertStringNotContainsString("table('workspace_plan_catalog')", $source);
        $this->assertStringNotContainsString("table('workspace_plan_assignments')", $source);
        $this->assertStringNotContainsString('WorkspacePlanCatalog::', $source);
        $this->assertStringNotContainsString('WorkspacePlanAssignment::', $source);
    }
}
