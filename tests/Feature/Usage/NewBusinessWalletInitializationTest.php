<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Business\BusinessCreated;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §9.3/§14 — both confirmed Business-creation events
 * (BusinessCreated via BusinessManager, BusinessAssignedToWorkspace via
 * WorkspaceManager::createBusinessInWorkspace()) each result in exactly
 * one wallet, never zero, never two.
 */
class NewBusinessWalletInitializationTest extends TestCase
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

    public function test_business_assigned_to_workspace_creates_exactly_one_wallet(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $business = app(WorkspaceManager::class)->createBusinessInWorkspace(
            $customer->user_id,
            $customer,
            $workspace,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_usage_wallets', 1);
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id]);
    }

    public function test_business_created_legacy_onboarding_creates_exactly_one_wallet(): void
    {
        $customer = $this->createCustomer();

        $business = app(\App\Library\Business\BusinessManager::class)->createOrUpdateOnboardingBusiness(
            $customer,
            null,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_usage_wallets', 1);
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id]);
    }

    public function test_repeat_listener_invocation_for_the_same_business_never_duplicates_a_wallet(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $business = app(WorkspaceManager::class)->createBusinessInWorkspace(
            $customer->user_id,
            $customer,
            $workspace,
            $this->businessAttributes(),
        );

        // Simulate a duplicate event delivery by invoking the listener's
        // own underlying manager call again directly.
        app(\App\Library\Usage\UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $this->assertDatabaseCount('business_usage_wallets', 1);
    }

    /**
     * Genuine duplicate event delivery — corrected this round (Correction
     * Round 2, §9.3): re-dispatches the real, registered event a second
     * time for the same Business, rather than merely calling the manager
     * a second time directly, proving the EventServiceProvider-registered
     * listener itself is idempotent under redelivery.
     */
    public function test_genuine_duplicate_business_created_event_delivery_never_duplicates_a_wallet(): void
    {
        $customer = $this->createCustomer();

        $business = app(\App\Library\Business\BusinessManager::class)->createOrUpdateOnboardingBusiness(
            $customer,
            null,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_usage_wallets', 1);

        BusinessCreated::dispatch($business->id, $customer->user_id);

        $this->assertDatabaseCount('business_usage_wallets', 1);
    }

    public function test_genuine_duplicate_business_assigned_to_workspace_event_delivery_never_duplicates_a_wallet(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $business = app(WorkspaceManager::class)->createBusinessInWorkspace(
            $customer->user_id,
            $customer,
            $workspace,
            $this->businessAttributes(),
        );

        $this->assertDatabaseCount('business_usage_wallets', 1);

        BusinessAssignedToWorkspace::dispatch($business->id, $workspace->id, $customer->user_id);

        $this->assertDatabaseCount('business_usage_wallets', 1);
    }
}
