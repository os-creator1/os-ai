<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Library\Usage\BillingProfileManager;
use App\Models\Currency;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §7 — full payer-consent actor matrix. Only the
 * Workspace owner may set 'workspace'; only the direct Business
 * owner/customer may set 'business'; Active Admin, Staff, and either
 * side attempting the other's payer type are all denied; no
 * platform-administrator HTTP override exists at M2.
 */
class PayerConsentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function setUpScenario(): array
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $ownerCustomer = $this->createCustomer();
        $workspace = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes())->workspace;
        $ownerId = (int) $workspace->owner_user_id;

        $directOwnerCustomer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwnerCustomer, $workspace, $this->businessAttributes());
        $directOwnerId = (int) $directOwnerCustomer->user_id;

        $adminCustomer = $this->createCustomer();
        $adminId = (int) $adminCustomer->user_id;
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $adminId,
            'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $staffCustomer = $this->createCustomer();
        $staffId = (int) $staffCustomer->user_id;
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $staffId,
            'role' => WorkspaceMembershipRole::Staff, 'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $unrelatedCustomer = $this->createCustomer();
        $unrelatedId = (int) $unrelatedCustomer->user_id;

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        return compact('business', 'ownerId', 'directOwnerId', 'adminId', 'staffId', 'unrelatedId');
    }

    public function test_workspace_owner_may_set_payer_to_workspace(): void
    {
        $s = $this->setUpScenario();

        $updated = app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['ownerId'], 'Owner sets workspace.');

        $this->assertSame(PayerType::Workspace, $updated->payer_type);
    }

    public function test_direct_business_owner_may_set_payer_to_business(): void
    {
        $s = $this->setUpScenario();

        $updated = app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Business, $s['directOwnerId'], 'Direct owner sets business.');

        $this->assertSame(PayerType::Business, $updated->payer_type);
    }

    public function test_workspace_owner_may_not_set_payer_to_business(): void
    {
        $s = $this->setUpScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Business, $s['ownerId'], 'Denied.');
    }

    public function test_direct_business_owner_may_not_set_payer_to_workspace(): void
    {
        $s = $this->setUpScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['directOwnerId'], 'Denied.');
    }

    public function test_active_admin_may_never_change_payer(): void
    {
        $s = $this->setUpScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['adminId'], 'Denied.');
    }

    public function test_staff_may_never_change_payer(): void
    {
        $s = $this->setUpScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Business, $s['staffId'], 'Denied.');
    }

    public function test_unrelated_user_may_never_change_payer(): void
    {
        $s = $this->setUpScenario();

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(BillingProfileManager::class)->changePayer($s['business'], PayerType::Workspace, $s['unrelatedId'], 'Denied.');
    }
}
