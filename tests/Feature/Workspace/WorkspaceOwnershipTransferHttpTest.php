<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Workspace\WorkspaceTransitionType;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspaceTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 4 Slice 4F
 * (docs/automation/RFC-003-M4-SLICE-4F-CONTRACT.md, "Authorized behavior"):
 * the customer HTTP surface for Workspace ownership transfer, through the
 * existing WorkspaceManager::transferOwnership(). Covers route shape,
 * owner-only UI visibility, opaque-uid incoming-owner resolution, explicit
 * previous-owner disposition construction, selected-scope Business uid
 * resolution reuse, the resolver-vs-manager denial boundary, the
 * same-owner no-op, the deliberate redirect to the Workspace index rather
 * than this Workspace's own overview, and no-write guarantees on every
 * denied or invalid request.
 */
class WorkspaceOwnershipTransferHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    // --- Route shape ---------------------------------------------------

    public function test_transfer_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.ownership.transfer'));

        $route = Route::getRoutes()->getByName('customer.workspaces.ownership.transfer');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/ownership/transfer', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    // --- Guests ----------------------------------------------------------

    public function test_guest_is_rejected_by_transfer(): void
    {
        $this->post(route('customer.workspaces.ownership.transfer', ['workspaceUid' => 'anything']))
            ->assertUnauthorized();
    }

    // --- UI visibility -----------------------------------------------------

    public function test_owner_sees_ownership_transfer_control(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('data-workspace-action="ownership/transfer"', false);
    }

    public function test_admin_does_not_see_ownership_transfer_control(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertDontSee('data-workspace-action="ownership/transfer"', false);
    }

    public function test_staff_does_not_see_ownership_transfer_control(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertDontSee('data-workspace-action="ownership/transfer"', false);
    }

    // --- Successful transfers ------------------------------------------

    public function test_owner_transfer_with_deactivate_succeeds(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertRedirect(route('customer.workspaces.index'));
        $response->assertSessionHas('flash_success');
        $this->assertSame($newOwner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_deactivate_disposition_deactivates_an_existing_previous_owner_membership(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;
        $previousOwnerMembership = WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $customer->user->id,
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $this->assertFalse($previousOwnerMembership->fresh()->is_active);
    }

    public function test_owner_transfer_with_convert_to_admin_all_scope_succeeds(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'all',
        ]);

        $response->assertRedirect(route('customer.workspaces.index'));
        $response->assertSessionHas('flash_success');
        $this->assertSame($newOwner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_owner_transfer_with_convert_to_admin_selected_scope_succeeds_with_exact_assignments(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;
        $selectedBusiness = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $unselectedBusiness = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'selected',
            'business_uids' => [$selectedBusiness->uid],
        ]);

        $response->assertRedirect(route('customer.workspaces.index'));
        $response->assertSessionHas('flash_success');

        $previousOwnerMembership = WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $customer->user->id)
            ->firstOrFail();

        $assignedBusinessIds = WorkspaceMembershipBusiness::where('workspace_membership_id', $previousOwnerMembership->id)
            ->pluck('business_id')
            ->all();

        $this->assertSame([$selectedBusiness->id], $assignedBusinessIds);
        $this->assertNotContains($unselectedBusiness->id, $assignedBusinessIds);
    }

    public function test_convert_to_admin_selected_scope_with_empty_business_uids_succeeds(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'selected',
            'business_uids' => [],
        ]);

        $response->assertRedirect(route('customer.workspaces.index'));
        $response->assertSessionHas('flash_success');
        $this->assertSame($newOwner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_previous_owner_convert_to_admin_remains_active_admin_with_requested_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'all',
        ]);

        $previousOwnerMembership = WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $customer->user->id)
            ->firstOrFail();

        $this->assertTrue($previousOwnerMembership->is_active);
        $this->assertSame(WorkspaceMembershipRole::Admin, $previousOwnerMembership->role);
        $this->assertSame(WorkspaceBusinessAccessScope::All, $previousOwnerMembership->business_access_scope);
    }

    // --- Incoming-owner resolution ------------------------------------------

    public function test_unknown_incoming_user_uid_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => 'does-not-exist',
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertNotFound();
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    // --- Denial: resolver vs manager boundary -------------------------------

    public function test_crafted_post_by_active_admin_is_denied_with_flash_error(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_crafted_post_by_active_staff_is_denied_with_flash_error(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_inactive_admin_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertNotFound();
    }

    public function test_unrelated_user_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertNotFound();
        $this->assertTrue($customer->exists);
    }

    public function test_platform_admin_only_user_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $customer->user->is_admin = true;
        $customer->user->save();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertNotFound();
    }

    // --- Denial: inactive Workspace ------------------------------------

    public function test_inactive_workspace_owner_is_denied_with_flash_error_and_no_transfer(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => false]);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    // --- Same-owner no-op ------------------------------------------------

    public function test_same_owner_transfer_by_actual_owner_is_a_successful_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $customer->user->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertRedirect(route('customer.workspaces.index'));
        $response->assertSessionHas('flash_success');
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    public function test_same_owner_transfer_by_unauthorized_actor_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $owner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
    }

    // --- Business uid resolution -----------------------------------------

    public function test_unknown_selected_business_uid_is_not_found_and_no_transfer(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'selected',
            'business_uids' => ['does-not-exist'],
        ]);

        $response->assertNotFound();
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    public function test_cross_workspace_selected_business_uid_is_not_found_and_no_transfer(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $otherWorkspace = $this->createWorkspace($customer->user);
        $foreignBusiness = $this->createBusinessForCustomer($customer->user->id, $otherWorkspace->id);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'selected',
            'business_uids' => [$foreignBusiness->uid],
        ]);

        $response->assertNotFound();
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    // --- Validation ----------------------------------------------------

    public function test_all_scope_with_business_selections_fails_validation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'convert_to_admin',
            'business_access_scope' => 'all',
            'business_uids' => [$business->uid],
        ]);

        $response->assertSessionHasErrors('business_uids');
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    public function test_deactivate_with_scope_input_fails_validation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
            'business_access_scope' => 'all',
        ]);

        $response->assertSessionHasErrors('business_access_scope');
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    public function test_deactivate_with_business_selections_fails_validation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
            'business_uids' => [$business->uid],
        ]);

        $response->assertSessionHasErrors('business_uids');
        $this->assertSame($customer->user->id, $workspace->fresh()->owner_user_id);
    }

    // --- Incoming membership retention ------------------------------------

    public function test_active_incoming_membership_remains_persisted_but_inactive_after_transfer(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $fresh = $incomingMembership->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->is_active);
    }

    // --- Transition/audit --------------------------------------------------

    public function test_transition_audit_row_exists_after_transfer(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $newOwner = $this->createCustomer()->user;

        $this->post(route('customer.workspaces.ownership.transfer', $workspace->uid), [
            'new_owner_user_uid' => $newOwner->uid,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $this->assertTrue(
            WorkspaceTransition::where('workspace_id', $workspace->id)
                ->where('transition_type', WorkspaceTransitionType::OwnershipTransferred)
                ->where('to_owner_user_id', $newOwner->id)
                ->exists()
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
