<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Business\BusinessCreated;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §35/§13 — both confirmed Business-creation events
 * each result in exactly one payer assignment, never zero, never two,
 * mirroring NewBusinessWalletInitializationTest.php's own M1 pattern
 * exactly, including real production call paths.
 */
class NewBusinessPayerAssignmentInitializationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = $this->createWorkspace($owner);

        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture-admin-' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    public function test_business_assigned_to_workspace_creates_exactly_one_payer_assignment(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $business = app(WorkspaceManager::class)->createBusinessInWorkspace(
            $customer->user_id,
            $customer,
            $workspace,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_payer_assignments', 1);
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
    }

    public function test_business_created_legacy_onboarding_creates_exactly_one_payer_assignment(): void
    {
        $customer = $this->createCustomer();

        $business = app(\App\Library\Business\BusinessManager::class)->createOrUpdateOnboardingBusiness(
            $customer,
            null,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_payer_assignments', 1);
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id]);
    }

    public function test_genuine_duplicate_business_created_event_delivery_never_duplicates_a_payer_assignment(): void
    {
        $customer = $this->createCustomer();

        $business = app(\App\Library\Business\BusinessManager::class)->createOrUpdateOnboardingBusiness(
            $customer,
            null,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_payer_assignments', 1);

        BusinessCreated::dispatch($business->id, $customer->user_id);

        $this->assertDatabaseCount('business_payer_assignments', 1);
    }

    public function test_genuine_duplicate_business_assigned_to_workspace_event_delivery_never_duplicates_a_payer_assignment(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $business = app(WorkspaceManager::class)->createBusinessInWorkspace(
            $customer->user_id,
            $customer,
            $workspace,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_payer_assignments', 1);

        BusinessAssignedToWorkspace::dispatch($business->id, $workspace->id, $customer->user_id);

        $this->assertDatabaseCount('business_payer_assignments', 1);
    }

    public function test_repeat_manager_invocation_for_the_same_business_never_duplicates_an_assignment(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $business = app(WorkspaceManager::class)->createBusinessInWorkspace(
            $customer->user_id,
            $customer,
            $workspace,
            $this->businessAttributes(),
        );

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertDatabaseCount('business_payer_assignments', 1);
    }
}
