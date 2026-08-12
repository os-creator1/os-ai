<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 4 Slice 4E
 * (docs/automation/RFC-003-M4-SLICE-4E-CONTRACT.md, "Authorized behavior"):
 * the customer HTTP surface for reassigning an existing Business between
 * Workspaces, through the existing WorkspaceManager::reassignBusiness()
 * (corrected this slice to also enforce the actor's own Business access to
 * the source Business, RFC-003 §7.5/§14.1). Covers route shape, opaque uid
 * targeting for the source Workspace, Business, and target Workspace,
 * owner/all-scope/selected-scope Admin success, the resolver-vs-manager
 * denial boundary (404 at resolveAccessibleWorkspace() vs flash-error from
 * WorkspaceManager), inactive-Workspace handling, unknown/foreign Business
 * handling, and regression of the existing effective-access read surfaces.
 */
class WorkspaceBusinessReassignmentHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    // --- Route shape -------------------------------------------------

    public function test_reassign_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.businesses.reassign'));

        $route = Route::getRoutes()->getByName('customer.workspaces.businesses.reassign');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/businesses/{businessUid}/reassign', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    // --- Guests ------------------------------------------------------------

    public function test_guest_is_rejected_by_reassign(): void
    {
        $this->post(route('customer.workspaces.businesses.reassign', ['workspaceUid' => 'anything', 'businessUid' => 'anything']))
            ->assertUnauthorized();
    }

    // --- Successful reassignment -------------------------------------------

    public function test_owner_can_reassign_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect(route('customer.workspaces.show', $sourceWorkspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertSame($targetWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_all_scope_active_admin_can_reassign_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $sourceWorkspace = $this->createWorkspace($ownerA);
        $targetWorkspace = $this->createWorkspace($ownerB);
        $this->createMembership($sourceWorkspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $this->createMembership($targetWorkspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $business = $this->createBusinessForCustomer($ownerA->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect(route('customer.workspaces.show', $sourceWorkspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertSame($targetWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_assigned_selected_scope_admin_can_reassign_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $sourceWorkspace = $this->createWorkspace($ownerA);
        $targetWorkspace = $this->createWorkspace($ownerB);
        $business = $this->createBusinessForCustomer($ownerA->id, $sourceWorkspace->id);
        $membership = $this->createMembership($sourceWorkspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $membership->id, 'business_id' => $business->id]);
        $this->createMembership($targetWorkspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect(route('customer.workspaces.show', $sourceWorkspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertSame($targetWorkspace->id, $business->fresh()->workspace_id);
    }

    // --- Selected-scope Admin: manager remains authoritative over the UI ---

    public function test_crafted_post_by_selected_scope_admin_for_an_unassigned_business_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $sourceWorkspace = $this->createWorkspace($owner);
        $targetWorkspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $sourceWorkspace->id);
        $this->createMembership($sourceWorkspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->createMembership($targetWorkspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertNotFound();
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_selected_scope_admin_overview_only_lists_accessible_source_businesses(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $assignedBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $unassignedBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $membership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $membership->id, 'business_id' => $assignedBusiness->id]);

        $showResponse = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $manageableUids = collect($showResponse->original->getData()['manageableBusinesses'])->pluck('uid')->all();

        $this->assertContains($assignedBusiness->uid, $manageableUids);
        $this->assertNotContains($unassignedBusiness->uid, $manageableUids);
    }

    // --- Denial: HTTP visibility precedence ---------------------------------

    public function test_authority_over_source_only_with_staff_visibility_in_target_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $ownerB = $this->createCustomer()->user;
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($ownerB);
        $this->createMembership($targetWorkspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_authority_over_target_only_with_staff_visibility_in_source_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $ownerA = $this->createCustomer()->user;
        $sourceWorkspace = $this->createWorkspace($ownerA);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $this->createMembership($sourceWorkspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);
        $business = $this->createBusinessForCustomer($ownerA->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_active_staff_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$workspace->uid, $business->uid]),
            ['target_workspace_uid' => $workspace->uid]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($workspace->id, $business->fresh()->workspace_id);
    }

    public function test_inactive_admin_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$workspace->uid, $business->uid]),
            ['target_workspace_uid' => $workspace->uid]
        );

        $response->assertNotFound();
    }

    public function test_unrelated_user_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$workspace->uid, $business->uid]),
            ['target_workspace_uid' => $workspace->uid]
        );

        $response->assertNotFound();
        $this->assertTrue($customer->exists);
    }

    public function test_is_admin_alone_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $customer->user->is_admin = true;
        $customer->user->save();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$workspace->uid, $business->uid]),
            ['target_workspace_uid' => $workspace->uid]
        );

        $response->assertNotFound();
    }

    // --- Denial: inactive Workspace ----------------------------------------

    public function test_inactive_source_workspace_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);
        $sourceWorkspace->is_active = false;
        $sourceWorkspace->save();

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_inactive_target_workspace_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user, ['is_active' => false]);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    // --- Denial: unknown/inaccessible/foreign targets -----------------------

    public function test_reassign_for_an_unknown_source_workspace_uid_is_not_found(): void
    {
        $this->actingAsHttpCustomer();

        $this->post(
            route('customer.workspaces.businesses.reassign', ['does-not-exist', 'also-does-not-exist']),
            ['target_workspace_uid' => 'irrelevant']
        )->assertNotFound();
    }

    public function test_reassign_for_an_inaccessible_source_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$workspace->uid, $business->uid]),
            ['target_workspace_uid' => $workspace->uid]
        );

        $response->assertNotFound();
        $this->assertTrue($customer->exists);
    }

    public function test_reassign_for_an_unknown_target_workspace_uid_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => 'does-not-exist']
        );

        $response->assertNotFound();
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_reassign_for_an_inaccessible_target_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $ownerB = $this->createCustomer()->user;
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($ownerB);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertNotFound();
        $this->assertSame($sourceWorkspace->id, $business->fresh()->workspace_id);
    }

    public function test_reassign_for_an_unknown_business_uid_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);

        $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, 'does-not-exist']),
            ['target_workspace_uid' => $targetWorkspace->uid]
        )->assertNotFound();
    }

    public function test_reassign_for_a_business_belonging_to_another_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $otherWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $otherWorkspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $response->assertNotFound();
        $this->assertSame($otherWorkspace->id, $business->fresh()->workspace_id);
    }

    // --- Data integrity and regression --------------------------------------

    public function test_successful_reassignment_changes_only_workspace_id(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);
        $originalCustomerId = $business->customer_id;
        $originalName = $business->name;

        $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $fresh = $business->fresh();
        $this->assertSame($targetWorkspace->id, $fresh->workspace_id);
        $this->assertSame($originalCustomerId, $fresh->customer_id);
        $this->assertSame($originalName, $fresh->name);
    }

    public function test_authorized_same_target_reassignment_is_a_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(
            route('customer.workspaces.businesses.reassign', [$workspace->uid, $business->uid]),
            ['target_workspace_uid' => $workspace->uid]
        );

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertSame($workspace->id, $business->fresh()->workspace_id);
    }

    public function test_stale_source_workspace_grants_are_removed_after_reassignment(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);
        $staffUser = $this->createCustomer()->user;
        $staffMembership = $this->createMembership($sourceWorkspace, $staffUser, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $staffMembership->id, 'business_id' => $business->id]);

        $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        );

        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $staffMembership->id)->count());
    }

    public function test_reassigned_business_is_reflected_in_both_workspaces_effective_lists(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $sourceWorkspace = $this->createWorkspace($customer->user);
        $targetWorkspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $sourceWorkspace->id);

        $this->post(
            route('customer.workspaces.businesses.reassign', [$sourceWorkspace->uid, $business->uid]),
            ['target_workspace_uid' => $targetWorkspace->uid]
        )->assertRedirect();

        $sourceShow = $this->get(route('customer.workspaces.show', $sourceWorkspace->uid))->assertOk();
        $this->assertEmpty($sourceShow->original->getData()['businesses']);

        $targetShow = $this->get(route('customer.workspaces.show', $targetWorkspace->uid))->assertOk();
        $this->assertTrue(
            collect($targetShow->original->getData()['businesses'])->contains(fn ($row) => $row['name'] === $business->name)
        );
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }
}
