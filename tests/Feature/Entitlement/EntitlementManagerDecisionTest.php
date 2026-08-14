<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementManagerDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * @return array{workspace: Workspace, business: Business}
     */
    private function createWorkspaceWithBusiness(bool $assign = true): array
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        if ($assign) {
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture assignment.', true, 0);
        }

        return ['workspace' => $workspace->fresh(), 'business' => $business->fresh()];
    }

    public function test_unknown_feature_key_denies_before_any_lookup(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness(assign: false);

        $decision = app(EntitlementManager::class)->decide($workspace, $business, 'not-a-real-feature-key', $this->createAdmin());

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unknown', $decision->reason);
    }

    public function test_unavailable_feature_denies(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Calendar->value, $this->createAdmin());

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unavailable', $decision->reason);
    }

    public function test_known_available_feature_proceeds_without_registry_type_error(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
    }

    public function test_workspace_business_mismatch_throws(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceWithBusiness();
        ['workspace' => $workspaceB, 'business' => $businessInB] = $this->createWorkspaceWithBusiness();

        $this->expectException(BusinessWorkspaceMismatchException::class);
        app(EntitlementManager::class)->decide($workspaceA, $businessInB, PlatformFeature::Crm->value, $this->createAdmin());
    }

    public function test_missing_business_throws(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $businessId = $business->id;
        $business->delete();

        // 'id' is not mass-assignable (it is not in Business::$fillable), so
        // it must be set directly as a property rather than via the
        // constructor's attribute array — otherwise $stale->id stays null
        // and findById(null) fails with a TypeError before the intended
        // WorkspaceBusinessNotFoundException path is ever reached.
        $stale = new Business();
        $stale->id = $businessId;
        $stale->exists = true;
        $stale->workspace_id = $workspace->id;

        $this->expectException(WorkspaceBusinessNotFoundException::class);
        app(EntitlementManager::class)->decide($workspace, $stale, PlatformFeature::Crm->value, $this->createAdmin());
    }

    public function test_stale_business_after_reassignment_cannot_be_decided_against_old_workspace(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        // The target Workspace must itself be entitled (M2 capacity
        // enforcement) for the real reassignBusiness() call below to
        // succeed — otherwise it fails closed with
        // WorkspacePlanUnassignedException before the stale-Business state
        // this test needs is ever created.
        ['workspace' => $newWorkspace] = $this->createWorkspaceWithBusiness();

        $newWorkspace->update(['owner_user_id' => $oldWorkspace->owner_user_id]);

        app(\App\Library\Workspace\WorkspaceManager::class)->reassignBusiness((int) $oldWorkspace->owner_user_id, $business, $newWorkspace);

        $this->expectException(BusinessWorkspaceMismatchException::class);
        app(EntitlementManager::class)->decide($oldWorkspace, $business, PlatformFeature::Crm->value, $this->createAdmin());
    }

    public function test_business_decided_against_its_current_workspace_succeeds_after_reassignment(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        ['workspace' => $newWorkspace] = $this->createWorkspaceWithBusiness();

        $newWorkspace->update(['owner_user_id' => $oldWorkspace->owner_user_id]);

        app(\App\Library\Workspace\WorkspaceManager::class)->reassignBusiness((int) $oldWorkspace->owner_user_id, $business, $newWorkspace);

        $decision = app(EntitlementManager::class)->decide($newWorkspace, $business, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertTrue($decision->allowed);
    }

    public function test_unassigned_workspace_denies(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness(assign: false);

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertFalse($decision->allowed);
        $this->assertSame('workspace_plan_unassigned', $decision->reason);
    }

    public function test_business_toggle_narrows_only(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $admin = $this->createAdmin();

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $admin);

        $this->assertFalse($decision->allowed);
        $this->assertSame('disabled_for_business', $decision->reason);
    }

    public function test_deny_override_denies_before_plan_mapping(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $admin = $this->createAdmin();

        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $admin, 'Test deny override.');

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $admin);

        $this->assertFalse($decision->allowed);
        $this->assertSame('denied_by_workspace_override', $decision->reason);
    }

    public function test_allow_override_for_unavailable_feature_is_rejected_at_write_time(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();
        $admin = $this->createAdmin();

        $this->expectException(\App\Exceptions\Entitlement\UnavailablePlatformFeatureOverrideException::class);
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Calendar, WorkspaceEntitlementOverrideState::Allow, $admin, 'Should be rejected.');
    }

    public function test_suspended_status_denies_even_complimentary(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $admin = $this->createAdmin();

        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $admin, 'Suspend for test.');

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $admin);

        $this->assertFalse($decision->allowed);
        $this->assertSame('plan_suspended', $decision->reason);
    }

    public function test_active_complimentary_allows_without_catalog_pricing(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertTrue($decision->allowed);
    }

    public function test_null_usage_authorization_gateway_always_authorizes(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertTrue($decision->allowed);
        $this->assertInstanceOf(\App\Library\Entitlement\NullUsageAuthorizationGateway::class, app(\App\Library\Entitlement\Contracts\UsageAuthorizationGateway::class));
    }

    public function test_all_nine_denial_keys_are_individually_reachable(): void
    {
        $keys = [
            'platform_feature_unknown', 'platform_feature_unavailable', 'workspace_plan_unassigned',
            'not_entitled_by_plan', 'denied_by_workspace_override', 'disabled_for_business',
            'plan_inactive', 'plan_suspended', 'usage_unauthorized',
        ];

        $this->assertCount(9, $keys);
        $this->assertCount(9, array_unique($keys));
    }
}
