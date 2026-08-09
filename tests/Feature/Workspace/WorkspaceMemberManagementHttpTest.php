<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 4 Slice 4B
 * (docs/automation/RFC-003-M4-SLICE-4B-CONTRACT.md, "Authorized behavior"):
 * the bounded customer Workspace-membership management HTTP surface --
 * add, role change, Business-access scope/assignment change, deactivate
 * and reactivate -- delegated entirely to the existing WorkspaceManager
 * membership methods. Covers route shape, validation, successful
 * add/role/scope flows, owner-only vs owner-or-active-Admin authority,
 * denial cases, opaque-uid Business resolution (unknown/duplicate/
 * cross-Workspace/inaccessible), deactivate/reactivate access retention,
 * the inactive-Workspace boundary, and opaque-uid-only exposure.
 */
class WorkspaceMemberManagementHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    // --- Route shape -----------------------------------------------------

    public function test_store_member_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.members.store'));

        $route = Route::getRoutes()->getByName('customer.workspaces.members.store');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/members', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_role_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.members.role'));

        $route = Route::getRoutes()->getByName('customer.workspaces.members.role');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/members/{memberUid}/role', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_access_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.members.access'));

        $route = Route::getRoutes()->getByName('customer.workspaces.members.access');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/members/{memberUid}/access', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_deactivate_member_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.members.deactivate'));

        $route = Route::getRoutes()->getByName('customer.workspaces.members.deactivate');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/members/{memberUid}/deactivate', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_reactivate_member_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.members.reactivate'));

        $route = Route::getRoutes()->getByName('customer.workspaces.members.reactivate');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/members/{memberUid}/reactivate', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_no_members_index_route_is_introduced(): void
    {
        $this->assertFalse(Route::has('customer.workspaces.members.index'));
    }

    // --- Guests ------------------------------------------------------------

    public function test_guest_is_rejected_by_store_member(): void
    {
        $this->post(route('customer.workspaces.members.store', 'anything'), ['user_uid' => 'x'])
            ->assertUnauthorized();
    }

    public function test_guest_is_rejected_by_role(): void
    {
        $this->post(route('customer.workspaces.members.role', ['workspaceUid' => 'anything', 'memberUid' => 'x']), ['role' => 'admin'])
            ->assertUnauthorized();
    }

    public function test_guest_is_rejected_by_access(): void
    {
        $this->post(route('customer.workspaces.members.access', ['workspaceUid' => 'anything', 'memberUid' => 'x']), ['business_access_scope' => 'all'])
            ->assertUnauthorized();
    }

    public function test_guest_is_rejected_by_deactivate_member(): void
    {
        $this->post(route('customer.workspaces.members.deactivate', ['workspaceUid' => 'anything', 'memberUid' => 'x']))
            ->assertUnauthorized();
    }

    public function test_guest_is_rejected_by_reactivate_member(): void
    {
        $this->post(route('customer.workspaces.members.reactivate', ['workspaceUid' => 'anything', 'memberUid' => 'x']))
            ->assertUnauthorized();
    }

    // --- CSRF boundary -------------------------------------------------
    //
    // These five mutation routes share the 'web' middleware group's
    // VerifyCsrfToken. Laravel's own VerifyCsrfToken::runningUnitTests()
    // bypasses that check under APP_ENV=testing, so the enforcement below
    // is re-armed for the duration of a single test via forceCsrfVerification().

    public function test_store_member_rejects_a_request_without_a_valid_csrf_token(): void
    {
        $this->forceCsrfVerification();
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Csrf', 'Missing');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertStatus(419);
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_store_member_succeeds_with_a_valid_csrf_token(): void
    {
        $this->forceCsrfVerification();
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Csrf', 'Valid');
        $token = 'test-csrf-token';

        $response = $this->withSession(['_token' => $token])->post(
            route('customer.workspaces.members.store', $workspace->uid),
            [
                '_token' => $token,
                'user_uid' => $target->uid,
                'role' => 'staff',
                'business_access_scope' => 'all',
            ]
        );

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $this->assertNotNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    // --- Validation ----------------------------------------------------

    public function test_store_member_requires_a_user_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertSessionHasErrors('user_uid');
    }

    public function test_store_member_requires_a_valid_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Val', 'Idate');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'owner',
            'business_access_scope' => 'all',
        ]);

        $response->assertSessionHasErrors('role');
    }

    public function test_store_member_requires_a_valid_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Val', 'Idate');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'partial',
        ]);

        $response->assertSessionHasErrors('business_access_scope');
    }

    public function test_store_member_rejects_business_uids_with_all_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Val', 'Idate');
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'business_uids' => [$business->uid],
        ]);

        $response->assertSessionHasErrors('business_uids');
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_store_member_rejects_duplicate_business_uids(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Val', 'Idate');
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'business_uids' => [$business->uid, $business->uid],
        ]);

        $response->assertSessionHasErrors();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_role_requires_a_valid_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Rho', 'Le');

        $response = $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), [
            'role' => 'owner',
        ]);

        $response->assertSessionHasErrors('role');
    }

    public function test_access_rejects_business_uids_with_all_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Sco', 'Pe');
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'all',
            'business_uids' => [$business->uid],
        ]);

        $response->assertSessionHasErrors('business_uids');
    }

    // --- Add member: success ---------------------------------------------

    public function test_owner_can_add_a_staff_member_with_all_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Ada', 'Staff');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
        $this->assertSame(WorkspaceBusinessAccessScope::All, $membership->business_access_scope);
        $this->assertTrue((bool) $membership->is_active);
    }

    public function test_owner_can_add_an_admin_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Ada', 'Admin');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'admin',
            'business_access_scope' => 'all',
        ])->assertRedirect();

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->first();
        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->role);
    }

    public function test_owner_can_add_a_member_with_selected_scope_and_assignments(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Sel', 'Ected');
        $businessA = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'business_uids' => [$businessA->uid, $businessB->uid],
        ])->assertRedirect();

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->first();
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->business_access_scope);
        $this->assertEqualsCanonicalizing(
            [$businessA->id, $businessB->id],
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->pluck('business_id')->all()
        );
    }

    public function test_owner_can_add_a_member_with_selected_scope_and_an_empty_set(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Emp', 'TySet');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'selected',
        ])->assertRedirect();

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->business_access_scope);
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    public function test_active_admin_can_add_a_staff_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $target = $this->createTargetUser('New', 'Staff');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertRedirect(route('customer.workspaces.show', $workspace->uid));

        $this->assertNotNull(WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->first());
    }

    public function test_selected_scope_admin_cannot_add_a_member_with_all_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $target = $this->createTargetUser('New', 'Staff');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertNotFound();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    // --- Add member: denial ------------------------------------------------

    public function test_active_admin_cannot_add_an_admin_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $target = $this->createTargetUser('New', 'Admin');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'admin',
            'business_access_scope' => 'all',
        ]);

        $response->assertNotFound();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_staff_cannot_add_members(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $target = $this->createTargetUser('New', 'Person');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertNotFound();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_staff_denial_is_not_distinguishable_from_an_unknown_user_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $target = $this->createTargetUser('Known', 'Target');

        $knownTargetResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $unknownTargetResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => 'does-not-exist',
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $knownTargetResponse->assertNotFound();
        $unknownTargetResponse->assertNotFound();
        $this->assertSame($knownTargetResponse->status(), $unknownTargetResponse->status());
    }

    public function test_inactive_admin_cannot_add_members(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);
        $target = $this->createTargetUser('New', 'Person');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertNotFound();

        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_unrelated_user_cannot_add_members(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $target = $this->createTargetUser('New', 'Person');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertNotFound();
    }

    public function test_users_parent_id_grants_no_add_authority(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $parentOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($parentOwner);
        $customer->user->parent_id = $parentOwner->id;
        $customer->user->save();
        $target = $this->createTargetUser('New', 'Person');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertNotFound();
    }

    public function test_is_admin_alone_grants_no_add_authority(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $unrelatedOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($unrelatedOwner);
        $customer->user->is_admin = true;
        $customer->user->save();
        $target = $this->createTargetUser('New', 'Person');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertNotFound();
    }

    public function test_owner_cannot_be_added_as_a_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $customer->user->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertNull(WorkspaceMembership::where('user_id', $customer->user->id)->first());
    }

    public function test_owner_target_denial_is_not_distinguishable_from_an_existing_member_denial(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $existingMember = $this->createTargetUser('Existing', 'Member');
        $this->createMembership($workspace, $existingMember, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $ownerTargetResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $owner->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);
        $ownerTargetResponse->assertSessionHas('flash_error', 'This user cannot be added as a member.');

        $existingMemberResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $existingMember->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);
        $existingMemberResponse->assertSessionHas('flash_error', 'This user cannot be added as a member.');
    }

    public function test_add_member_on_an_inactive_workspace_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => false]);
        $target = $this->createTargetUser('New', 'Person');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_staff_denial_on_an_inactive_workspace_is_not_distinguishable_from_an_unknown_user_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $target = $this->createTargetUser('Known', 'Target');
        $workspace->is_active = false;
        $workspace->save();

        $knownTargetResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $unknownTargetResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => 'does-not-exist',
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $knownTargetResponse->assertNotFound();
        $unknownTargetResponse->assertNotFound();
        $this->assertSame($knownTargetResponse->status(), $unknownTargetResponse->status());
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_add_member_with_an_unknown_user_uid_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => 'does-not-exist',
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertNotFound();
    }

    // --- Add member: duplicate behavior ------------------------------------

    public function test_duplicate_add_with_identical_role_and_scope_is_an_authorized_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Dup', 'Licate');

        $payload = ['user_uid' => $target->uid, 'role' => 'staff', 'business_access_scope' => 'all'];

        $this->post(route('customer.workspaces.members.store', $workspace->uid), $payload)->assertRedirect();
        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), $payload);

        $response->assertSessionHas('flash_success');
        $this->assertSame(1, WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->count());
    }

    public function test_duplicate_add_with_a_conflicting_role_is_rejected(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Con', 'Flict');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertRedirect();

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'admin',
            'business_access_scope' => 'all',
        ]);

        $response->assertSessionHas('flash_error');
        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->first();
        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
    }

    // --- Add member: Business uid resolution --------------------------------

    public function test_add_member_with_an_unknown_business_uid_writes_nothing(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $target = $this->createTargetUser('Bad', 'Uid');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'business_uids' => ['does-not-exist'],
        ]);

        $response->assertNotFound();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_add_member_with_a_cross_workspace_business_uid_writes_nothing(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $otherWorkspace = $this->createWorkspace($customer->user);
        $foreignBusiness = $this->createBusinessForCustomer($customer->user->id, $otherWorkspace->id);
        $target = $this->createTargetUser('Cross', 'Ws');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'business_uids' => [$foreignBusiness->uid],
        ]);

        $response->assertNotFound();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    public function test_add_member_business_selection_outside_admin_effective_access_writes_nothing(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $admin = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $allowedBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $forbiddenBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $admin->id, 'business_id' => $allowedBusiness->id]);
        $target = $this->createTargetUser('Scoped', 'Admin');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'user_uid' => $target->uid,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'business_uids' => [$forbiddenBusiness->uid],
        ]);

        $response->assertNotFound();
        $this->assertNull(WorkspaceMembership::where('user_id', $target->id)->first());
    }

    // --- Role change ---------------------------------------------------

    public function test_owner_can_change_a_members_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Role', 'Change', ['role' => WorkspaceMembershipRole::Staff]);

        $response = $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), [
            'role' => 'admin',
        ]);

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertSame(WorkspaceMembershipRole::Admin, $member->fresh()->role);
    }

    public function test_active_admin_cannot_change_a_members_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Role', 'Change', ['role' => WorkspaceMembershipRole::Staff]);

        $response = $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), [
            'role' => 'admin',
        ]);

        $response->assertNotFound();
        $this->assertSame(WorkspaceMembershipRole::Staff, $member->fresh()->role);
    }

    public function test_staff_cannot_change_a_members_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Role', 'Change', ['role' => WorkspaceMembershipRole::Staff]);

        $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), ['role' => 'admin'])
            ->assertNotFound();
        $this->assertSame(WorkspaceMembershipRole::Staff, $member->fresh()->role);
    }

    public function test_role_change_of_an_inactive_member_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dorm', 'Ant', ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $response = $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), [
            'role' => 'admin',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertSame(WorkspaceMembershipRole::Staff, $member->fresh()->role);
    }

    public function test_role_change_on_an_inactive_workspace_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dorm', 'Co', ['role' => WorkspaceMembershipRole::Staff]);
        $workspace->is_active = false;
        $workspace->save();

        $response = $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), [
            'role' => 'admin',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertSame(WorkspaceMembershipRole::Staff, $member->fresh()->role);
    }

    public function test_role_change_of_an_unknown_member_uid_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $this->post(route('customer.workspaces.members.role', [$workspace->uid, 'does-not-exist']), ['role' => 'admin'])
            ->assertNotFound();
    }

    public function test_role_change_of_a_user_with_no_membership_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $unrelated = $this->createTargetUser('No', 'Membership');

        $this->post(route('customer.workspaces.members.role', [$workspace->uid, $unrelated->uid]), ['role' => 'admin'])
            ->assertNotFound();
    }

    public function test_staff_role_change_denial_on_an_inactive_member_is_not_distinguishable_from_an_unknown_member_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $inactiveMember = $this->createNamedMember($workspace, 'Ina', 'Ctive', ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $knownTargetResponse = $this->post(route('customer.workspaces.members.role', [$workspace->uid, $inactiveMember->user->uid]), ['role' => 'admin']);
        $unknownTargetResponse = $this->post(route('customer.workspaces.members.role', [$workspace->uid, 'does-not-exist']), ['role' => 'admin']);

        $knownTargetResponse->assertNotFound();
        $unknownTargetResponse->assertNotFound();
        $this->assertSame($knownTargetResponse->status(), $unknownTargetResponse->status());
        $this->assertSame(WorkspaceMembershipRole::Staff, $inactiveMember->fresh()->role);
    }

    // --- Business access scope / assignment change --------------------------

    public function test_owner_can_change_scope_from_all_to_selected_with_assignments(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Sco', 'Peme', ['business_access_scope' => WorkspaceBusinessAccessScope::All]);
        $businessA = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'selected',
            'business_uids' => [$businessA->uid, $businessB->uid],
        ]);

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $fresh = $member->fresh();
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $fresh->business_access_scope);
        $this->assertEqualsCanonicalizing(
            [$businessA->id, $businessB->id],
            WorkspaceMembershipBusiness::where('workspace_membership_id', $fresh->id)->pluck('business_id')->all()
        );
    }

    public function test_active_admin_can_change_a_staff_members_access_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Sta', 'Ffer', ['role' => WorkspaceMembershipRole::Staff]);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'all',
        ]);

        $response->assertSessionHas('flash_success');
        $this->assertSame(WorkspaceBusinessAccessScope::All, $member->fresh()->business_access_scope);
    }

    public function test_selected_scope_admin_cannot_grant_all_scope_access(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Sta', 'Ffer', ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'all',
        ]);

        $response->assertNotFound();
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $member->fresh()->business_access_scope);
    }

    public function test_staff_cannot_change_a_members_access_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Sta', 'Ffer', ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'selected',
        ])->assertNotFound();

        $this->assertSame(WorkspaceBusinessAccessScope::All, $member->fresh()->business_access_scope);
    }

    public function test_access_change_selection_outside_admin_effective_access_writes_nothing(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $admin = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $allowedBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $forbiddenBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $admin->id, 'business_id' => $allowedBusiness->id]);
        $member = $this->createNamedMember($workspace, 'Tar', 'Get', ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'selected',
            'business_uids' => [$forbiddenBusiness->uid],
        ]);

        $response->assertNotFound();
        $this->assertSame(WorkspaceBusinessAccessScope::All, $member->fresh()->business_access_scope);
    }

    public function test_access_change_of_an_inactive_member_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dorm', 'Ant', ['is_active' => false, 'business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'selected',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertSame(WorkspaceBusinessAccessScope::All, $member->fresh()->business_access_scope);
    }

    public function test_access_change_on_an_inactive_workspace_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dorm', 'Co', ['business_access_scope' => WorkspaceBusinessAccessScope::All]);
        $workspace->is_active = false;
        $workspace->save();

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), [
            'business_access_scope' => 'selected',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertSame(WorkspaceBusinessAccessScope::All, $member->fresh()->business_access_scope);
    }

    public function test_staff_access_change_denial_on_an_inactive_member_is_not_distinguishable_from_an_unknown_member_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $inactiveMember = $this->createNamedMember($workspace, 'Ina', 'Ctive', ['is_active' => false, 'business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $knownTargetResponse = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $inactiveMember->user->uid]), ['business_access_scope' => 'selected']);
        $unknownTargetResponse = $this->post(route('customer.workspaces.members.access', [$workspace->uid, 'does-not-exist']), ['business_access_scope' => 'selected']);

        $knownTargetResponse->assertNotFound();
        $unknownTargetResponse->assertNotFound();
        $this->assertSame($knownTargetResponse->status(), $unknownTargetResponse->status());
        $this->assertSame(WorkspaceBusinessAccessScope::All, $inactiveMember->fresh()->business_access_scope);
    }

    public function test_active_admin_can_still_see_the_reactivation_prompt_for_an_inactive_admin_members_access_change(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $inactiveAdmin = $this->createNamedMember($workspace, 'Ina', 'Ctive', [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);

        $response = $this->post(route('customer.workspaces.members.access', [$workspace->uid, $inactiveAdmin->user->uid]), [
            'business_access_scope' => 'selected',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertSame(WorkspaceBusinessAccessScope::All, $inactiveAdmin->fresh()->business_access_scope);
    }

    public function test_role_and_scope_changes_are_independent(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Ind', 'Ep', [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);

        $this->post(route('customer.workspaces.members.role', [$workspace->uid, $member->user->uid]), ['role' => 'admin'])
            ->assertSessionHas('flash_success');

        $afterRoleChange = $member->fresh();
        $this->assertSame(WorkspaceMembershipRole::Admin, $afterRoleChange->role);
        $this->assertSame(WorkspaceBusinessAccessScope::All, $afterRoleChange->business_access_scope);

        $this->post(route('customer.workspaces.members.access', [$workspace->uid, $member->user->uid]), ['business_access_scope' => 'selected'])
            ->assertSessionHas('flash_success');

        $afterScopeChange = $member->fresh();
        $this->assertSame(WorkspaceMembershipRole::Admin, $afterScopeChange->role);
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $afterScopeChange->business_access_scope);
    }

    // --- Deactivate / reactivate ---------------------------------------

    public function test_owner_can_deactivate_a_staff_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dea', 'Ct', ['role' => WorkspaceMembershipRole::Staff]);

        $response = $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]));

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    public function test_owner_can_deactivate_an_admin_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dea', 'Ct', ['role' => WorkspaceMembershipRole::Admin]);

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_success');
        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    public function test_active_admin_can_deactivate_a_staff_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Sta', 'Ff', ['role' => WorkspaceMembershipRole::Staff]);

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_success');
        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    public function test_active_admin_cannot_deactivate_an_admin_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Oth', 'Er', ['role' => WorkspaceMembershipRole::Admin]);

        $response = $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]));

        $response->assertNotFound();
        $this->assertTrue((bool) $member->fresh()->is_active);
    }

    public function test_staff_cannot_deactivate_a_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $member = $this->createNamedMember($workspace, 'Oth', 'Er', ['role' => WorkspaceMembershipRole::Staff]);

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))
            ->assertNotFound();
        $this->assertTrue((bool) $member->fresh()->is_active);
    }

    public function test_deactivating_an_already_inactive_member_is_an_authorized_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Alr', 'Eady', ['is_active' => false]);

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_success');
        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    public function test_deactivate_member_on_an_inactive_workspace_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dorm', 'Co');
        $workspace->is_active = false;
        $workspace->save();

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_error');
        $this->assertTrue((bool) $member->fresh()->is_active);
    }

    public function test_deactivation_retains_assignments_and_removes_effective_access(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Ret', 'Ain', ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $member->id, 'business_id' => $business->id]);

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $member->id)->count());

        $this->assertFalse(
            app(\App\Library\Workspace\WorkspaceManager::class)->userCanAccessBusiness((int) $member->user->id, $business->fresh())
        );
    }

    public function test_reactivation_restores_previously_retained_access(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Res', 'Tore', ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $member->id, 'business_id' => $business->id]);

        $this->post(route('customer.workspaces.members.deactivate', [$workspace->uid, $member->user->uid]))->assertSessionHas('flash_success');
        $this->post(route('customer.workspaces.members.reactivate', [$workspace->uid, $member->user->uid]))->assertSessionHas('flash_success');

        $this->assertTrue((bool) $member->fresh()->is_active);
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $member->id)->count());

        $manager = app(\App\Library\Workspace\WorkspaceManager::class);
        $this->assertTrue($manager->userCanAccessBusiness((int) $member->user->id, $business->fresh()));
    }

    public function test_reactivating_an_already_active_member_is_an_authorized_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Alr', 'Eady', ['is_active' => true]);

        $this->post(route('customer.workspaces.members.reactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_success');
        $this->assertTrue((bool) $member->fresh()->is_active);
    }

    public function test_reactivate_member_on_an_inactive_workspace_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Dorm', 'Co', ['is_active' => false]);
        $workspace->is_active = false;
        $workspace->save();

        $this->post(route('customer.workspaces.members.reactivate', [$workspace->uid, $member->user->uid]))
            ->assertSessionHas('flash_error');
        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    // --- Opaque uid / no raw identifier exposure ----------------------------

    public function test_directory_rows_carry_uid_and_active_state_but_no_raw_identifiers(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $active = $this->createNamedMember($workspace, 'Act', 'Ive', ['is_active' => true]);
        $inactive = $this->createNamedMember($workspace, 'Ina', 'Ctive', ['is_active' => false]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $rows = $response->original->getData()['directory'];
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['uid', 'name', 'role', 'scope', 'assigned_business_count', 'assigned_business_uids', 'is_active'],
            array_keys($rows[0])
        );
        $uids = array_column($rows, 'uid');
        $this->assertContains($active->user->uid, $uids);
        $this->assertContains($inactive->user->uid, $uids);
        $activeRow = collect($rows)->firstWhere('uid', $active->user->uid);
        $inactiveRow = collect($rows)->firstWhere('uid', $inactive->user->uid);
        $this->assertTrue($activeRow['is_active']);
        $this->assertFalse($inactiveRow['is_active']);
        $response->assertDontSee($active->user->email);
        $response->assertDontSee($inactive->user->email);
    }

    /**
     * transferOwnership() deliberately retains a prior membership row for
     * a user who becomes owner, deactivated rather than deleted (RFC-003
     * §7.3's owner-never-holds-a-membership-row invariant applies going
     * forward, not retroactively to that retained row). The directory
     * must exclude it -- the owner is never one of their own Workspace's
     * listed members -- regardless of how that retained row's role or
     * active state ended up set.
     */
    public function test_directory_excludes_the_owners_own_retained_membership_row(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);
        $genuineMember = $this->createNamedMember($workspace, 'Gen', 'Uine', ['is_active' => true]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $rows = $response->original->getData()['directory'];
        $this->assertCount(1, $rows);
        $this->assertSame($genuineMember->user->uid, $rows[0]['uid']);
    }

    public function test_owners_retained_membership_row_cannot_be_targeted_by_role_change(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => false,
        ]);

        $this->post(route('customer.workspaces.members.role', [$workspace->uid, $customer->user->uid]), ['role' => 'admin'])
            ->assertNotFound();
    }

    public function test_owners_retained_membership_row_cannot_be_reactivated(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $ownerMembership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);

        $this->post(route('customer.workspaces.members.reactivate', [$workspace->uid, $customer->user->uid]))
            ->assertNotFound();
        $this->assertFalse($ownerMembership->fresh()->is_active);
    }

    public function test_manageable_businesses_carry_uid_and_no_raw_identifiers(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $rows = $response->original->getData()['manageableBusinesses'];
        $this->assertCount(1, $rows);
        $this->assertSame(['uid', 'name'], array_keys($rows[0]));
        $this->assertSame($business->uid, $rows[0]['uid']);
    }

    public function test_active_staff_receives_no_manageable_businesses_data(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $this->assertArrayNotHasKey('manageableBusinesses', $response->original->getData());
        $this->assertArrayNotHasKey('directory', $response->original->getData());
    }

    // --- Member-management view rendering -----------------------------------

    public function test_access_form_pre_checks_currently_assigned_businesses(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Priya', 'Shah', [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $assignedBusiness = $this->createBusinessForCustomer($member->user->id, $workspace->id);
        $unassignedBusiness = $this->createBusinessForCustomer($member->user->id, $workspace->id);
        WorkspaceMembershipBusiness::create([
            'workspace_membership_id' => $member->id,
            'business_id' => $assignedBusiness->id,
        ]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $html = $response->getContent();

        $assignedInputId = 'access-' . $member->user->uid . '-' . $assignedBusiness->uid;
        $unassignedInputId = 'access-' . $member->user->uid . '-' . $unassignedBusiness->uid;

        $this->assertMatchesRegularExpression(
            '/id="' . preg_quote($assignedInputId, '/') . '"[^>]*\schecked/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="' . preg_quote($unassignedInputId, '/') . '"[^>]*\schecked/',
            $html
        );
    }

    public function test_access_scope_script_clears_stale_business_selections_when_all_is_chosen(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedMember($workspace, 'Nia', 'Cole', [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'select[name="business_access_scope"]',
            $html,
            'Expected a script that reconciles the business_uids checkboxes with the chosen access scope.'
        );
        $this->assertStringContainsString(
            "checkbox.checked = false;",
            $html,
            'Expected assigned-Business checkboxes to be cleared when "All Businesses" is selected, so an unmodified submit cannot fail UpdateWorkspaceMemberAccessRequest\'s all-scope validation.'
        );
    }

    public function test_active_admin_does_not_see_the_role_change_form(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $this->createNamedMember($workspace, 'Sta', 'Ffer', ['role' => WorkspaceMembershipRole::Staff]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertDontSee('data-member-action="role"', false);
        $response->assertDontSee('Change role');
    }

    public function test_active_admin_does_not_see_lifecycle_controls_for_an_admin_target_but_does_for_staff(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $adminTarget = $this->createNamedMember($workspace, 'Ano', 'Ther', ['role' => WorkspaceMembershipRole::Admin]);
        $staffTarget = $this->createNamedMember($workspace, 'Sta', 'Ffer', ['role' => WorkspaceMembershipRole::Staff]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/data-member-action="deactivate" data-member-uid="' . preg_quote($adminTarget->user->uid, '/') . '"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-member-action="deactivate" data-member-uid="' . preg_quote($staffTarget->user->uid, '/') . '"/',
            $html
        );
    }

    public function test_active_admin_does_not_see_the_admin_option_on_the_add_member_form(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertDontSee('<option value="admin">Admin</option>', false);
    }

    public function test_owner_sees_the_admin_option_on_the_add_member_form(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('<option value="admin">Admin</option>', false);
    }

    public function test_admin_cannot_change_access_for_a_member_with_businesses_outside_their_effective_access(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $admin = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $visibleBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $hiddenBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $admin->id, 'business_id' => $visibleBusiness->id]);

        $target = $this->createNamedMember($workspace, 'Hid', 'Den', [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $target->id, 'business_id' => $visibleBusiness->id]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $target->id, 'business_id' => $hiddenBusiness->id]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/data-member-action="access" data-member-uid="' . preg_quote($target->user->uid, '/') . '"/',
            $html
        );
        $this->assertStringContainsString(
            "Business access can only be changed by a manager who can see this member's complete assigned Businesses.",
            $html
        );
    }

    public function test_admin_can_change_access_for_a_member_whose_businesses_are_fully_visible(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $admin = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $visibleBusiness = $this->createBusinessForCustomer($owner->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $admin->id, 'business_id' => $visibleBusiness->id]);

        $target = $this->createNamedMember($workspace, 'Vis', 'Ible', [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $target->id, 'business_id' => $visibleBusiness->id]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/data-member-action="access" data-member-uid="' . preg_quote($target->user->uid, '/') . '"/',
            $html
        );
    }

    public function test_member_action_forms_are_bound_after_they_exist_in_the_markup(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedMember($workspace, 'Dana', 'Lee', ['is_active' => true]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $html = $response->getContent();

        $scriptPosition = strpos($html, "document.querySelectorAll('form[data-member-action]')");
        $lastFormPosition = strrpos($html, 'data-member-action=');

        $this->assertNotFalse($scriptPosition, 'Expected the member-action binding script to be present.');
        $this->assertNotFalse($lastFormPosition, 'Expected at least one member-action form to be rendered.');
        $this->assertGreaterThan(
            $lastFormPosition,
            $scriptPosition,
            'The member-action binding script must run after every member-action form already exists in the markup.'
        );
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * Re-arms CSRF verification for the current test only. Laravel's own
     * VerifyCsrfToken::runningUnitTests() otherwise bypasses the check
     * under APP_ENV=testing, so the CSRF-boundary tests above bind a
     * subclass that forces enforcement instead of reimplementing the
     * middleware.
     */
    private function forceCsrfVerification(): void
    {
        $this->app->bind(\App\Http\Middleware\VerifyCsrfToken::class, function ($app) {
            return new class($app, $app->make('encrypter')) extends \App\Http\Middleware\VerifyCsrfToken {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    private function createTargetUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower($firstName) . '.' . strtolower($lastName) . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
    }

    private function createNamedMember(
        Workspace $workspace,
        string $firstName,
        string $lastName,
        array $overrides = []
    ): WorkspaceMembership {
        $user = $this->createTargetUser($firstName, $lastName);

        return $this->createMembership($workspace, $user, $overrides);
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
