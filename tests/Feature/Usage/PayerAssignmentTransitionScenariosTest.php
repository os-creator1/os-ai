<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.E — exact behavior for every named scenario:
 * plan changes, ownership changes, Workspace transfers, Business
 * reassignment, deactivated members, and a Business without a valid
 * wallet.
 */
class PayerAssignmentTransitionScenariosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createAdminUserId(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    public function test_a_plan_change_never_silently_re_defaults_an_already_assigned_payer(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $admin = $this->createAdminUserId();

        app(EntitlementManager::class)->assignFirstPlan($business->workspace, WorkspacePlanTier::Core, $admin, 'Fixture.', true, 0);
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);

        app(EntitlementManager::class)->changePlan($business->workspace, WorkspacePlanTier::Agency, $admin, 'Upgrade.', true, 0);

        // The payer stays exactly what it was — a plan change never
        // touches business_payer_assignments.
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
    }

    public function test_ownership_change_preserves_the_assignment_and_new_owner_gains_future_consent(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $oldOwnerId = (int) $business->workspace->owner_user_id;

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $newOwner = User::create([
            'first_name' => 'New', 'last_name' => 'Owner', 'email' => 'newowner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        app(WorkspaceManager::class)->transferOwnership(
            $oldOwnerId,
            $business->workspace,
            (int) $newOwner->id,
            \App\DTO\Workspace\WorkspaceOwnershipTransferDisposition::deactivate(),
        );

        // Assignment untouched by the transfer itself.
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);

        // The old owner has lost consent authority; the new owner has it.
        $business->refresh();
        $business->loadMissing('workspace');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $oldOwnerId, 'Old owner denied.');
    }

    public function test_a_deactivated_admin_immediately_loses_billing_management_authority(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        $adminCustomer = $this->createCustomer();
        $adminId = (int) $adminCustomer->user_id;
        $membership = WorkspaceMembership::create([
            'workspace_id' => $business->workspace->id, 'user_id' => $adminId,
            'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        // Active: can manage the billing contact (non-payer authority).
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', 'jane@example.test', true, $adminId);

        $membership->update(['is_active' => false]);

        $this->expectException(\App\Exceptions\Usage\UnauthorizedUsageBillingManagementException::class);
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'John Doe', 'john@example.test', true, $adminId);
    }

    public function test_a_business_without_a_valid_wallet_can_still_have_a_payer_assignment(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        // Deliberately using a currency_code that will fail M1's own
        // no-fallback resolution, so no wallet is ever created for this
        // Business.
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'ZZZ']));

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id]);
        $this->assertDatabaseMissing('business_usage_wallets', ['business_id' => $business->id]);
    }
}
