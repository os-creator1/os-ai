<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class WorkspaceModelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createWorkspace(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'name' => 'Test Workspace',
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ], $overrides));
    }

    private function createMembership(Workspace $workspace, User $user, array $overrides = []): WorkspaceMembership
    {
        return WorkspaceMembership::create(array_merge([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ], $overrides));
    }

    private function createBusinessForCustomer(int $customerId, ?int $workspaceId = null): Business
    {
        $business = new Business($this->businessAttributes());
        $business->customer_id = $customerId;
        $business->workspace_id = $workspaceId;
        $business->save();

        return $business;
    }

    // 1. Workspace ordinary Eloquent creation generates a valid UUID.
    public function test_workspace_creation_generates_a_valid_uuid(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->assertTrue(Str::isUuid($workspace->uid));
    }

    // 2. An explicitly supplied valid Workspace uid is preserved.
    public function test_explicitly_supplied_uid_is_preserved(): void
    {
        $owner = $this->createCustomer()->user;
        $uid = (string) Str::uuid();

        $workspace = $this->createWorkspace($owner, ['uid' => $uid]);

        $this->assertSame($uid, $workspace->uid);
    }

    // 3. Workspace route key behavior uses uid consistently with existing UID-routed models.
    public function test_workspace_route_key_name_matches_other_uid_routed_models(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->assertSame('uid', $workspace->getRouteKeyName());
        $this->assertSame('uid', (new Business())->getRouteKeyName());
    }

    // 4. Workspace is_active casts to boolean.
    public function test_workspace_is_active_casts_to_boolean(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => 1]);

        $this->assertIsBool($workspace->fresh()->is_active);
        $this->assertTrue($workspace->fresh()->is_active);
    }

    // 5. Workspace owner relationship resolves correctly.
    public function test_workspace_owner_relationship_resolves(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->assertTrue($workspace->owner->is($owner));
    }

    // 6. Workspace memberships relationship resolves all memberships.
    public function test_workspace_memberships_relationship_resolves_all_memberships(): void
    {
        $owner = $this->createCustomer()->user;
        $memberA = $this->createCustomer()->user;
        $memberB = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->createMembership($workspace, $memberA);
        $this->createMembership($workspace, $memberB, ['is_active' => false]);

        $this->assertCount(2, $workspace->memberships()->get());
    }

    // 7. Workspace activeMemberships excludes inactive rows.
    public function test_workspace_active_memberships_excludes_inactive_rows(): void
    {
        $owner = $this->createCustomer()->user;
        $activeMember = $this->createCustomer()->user;
        $inactiveMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->createMembership($workspace, $activeMember, ['is_active' => true]);
        $this->createMembership($workspace, $inactiveMember, ['is_active' => false]);

        $activeUserIds = $workspace->activeMemberships()->pluck('user_id');

        $this->assertCount(1, $activeUserIds);
        $this->assertTrue($activeUserIds->contains($activeMember->id));
    }

    // 8. Workspace businesses relationship resolves Businesses with matching workspace_id.
    public function test_workspace_businesses_relationship_resolves_matching_businesses(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherCustomer->user);

        $inWorkspace = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $this->createBusinessForCustomer($otherCustomer->user_id, $otherWorkspace->id);

        $businesses = $workspace->businesses()->get();

        $this->assertCount(1, $businesses);
        $this->assertTrue($businesses->first()->is($inWorkspace));
    }

    // 9. WorkspaceMembership role casts to WorkspaceMembershipRole.
    public function test_workspace_membership_role_casts_to_enum(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Admin]);

        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->fresh()->role);
    }

    // 10. WorkspaceMembership business_access_scope casts to WorkspaceBusinessAccessScope.
    public function test_workspace_membership_business_access_scope_casts_to_enum(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->createMembership($workspace, $member, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->fresh()->business_access_scope);
    }

    // 11. WorkspaceMembership is_active casts to boolean.
    public function test_workspace_membership_is_active_casts_to_boolean(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->createMembership($workspace, $member, ['is_active' => 0]);

        $this->assertIsBool($membership->fresh()->is_active);
        $this->assertFalse($membership->fresh()->is_active);
    }

    // 12. WorkspaceMembership workspace and user relationships resolve.
    public function test_workspace_membership_workspace_and_user_relationships_resolve(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->createMembership($workspace, $member);

        $this->assertTrue($membership->workspace->is($workspace));
        $this->assertTrue($membership->user->is($member));
    }

    // 13. assignedBusinesses resolves only pivot-assigned Businesses.
    public function test_assigned_businesses_resolves_only_pivot_assigned_businesses(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $member, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $assigned = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $unassigned = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        WorkspaceMembershipBusiness::create([
            'workspace_membership_id' => $membership->id,
            'business_id' => $assigned->id,
        ]);

        $result = $membership->assignedBusinesses()->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($assigned));
        $this->assertFalse($result->pluck('id')->contains($unassigned->id));
    }

    // 14. WorkspaceMembershipBusiness membership and business relationships resolve.
    public function test_workspace_membership_business_relationships_resolve(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $assignment = WorkspaceMembershipBusiness::create([
            'workspace_membership_id' => $membership->id,
            'business_id' => $business->id,
        ]);

        $this->assertTrue($assignment->membership->is($membership));
        $this->assertTrue($assignment->business->is($business));
    }

    // 15. User may own multiple Workspaces.
    public function test_user_may_own_multiple_workspaces(): void
    {
        $owner = $this->createCustomer()->user;

        $this->createWorkspace($owner, ['name' => 'Workspace One']);
        $this->createWorkspace($owner, ['name' => 'Workspace Two']);

        $this->assertCount(2, $owner->ownedWorkspaces()->get());
    }

    // 16. User may hold memberships in multiple Workspaces.
    public function test_user_may_hold_memberships_in_multiple_workspaces(): void
    {
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($ownerA);
        $workspaceB = $this->createWorkspace($ownerB);

        $this->createMembership($workspaceA, $member);
        $this->createMembership($workspaceB, $member);

        $this->assertCount(2, $member->workspaceMemberships()->get());
    }

    // 17. activeWorkspaceMemberships excludes inactive memberships.
    public function test_active_workspace_memberships_excludes_inactive_memberships(): void
    {
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($ownerA);
        $workspaceB = $this->createWorkspace($ownerB);

        $this->createMembership($workspaceA, $member, ['is_active' => true]);
        $this->createMembership($workspaceB, $member, ['is_active' => false]);

        $this->assertCount(1, $member->activeWorkspaceMemberships()->get());
    }

    // 18. Business::workspace() and Business::customer() may resolve to different Users without conflict.
    public function test_business_workspace_and_customer_may_resolve_different_users(): void
    {
        $businessOwner = $this->createCustomer();
        $workspaceOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($workspaceOwner);

        $business = $this->createBusinessForCustomer($businessOwner->user_id, $workspace->id);

        $this->assertTrue($business->workspace->is($workspace));
        $this->assertTrue($business->customer->is($businessOwner));
        $this->assertNotSame($workspace->owner_user_id, $business->customer_id);
    }

    // 19. users.parent_id creates no Workspace relationship or membership.
    public function test_users_parent_id_creates_no_workspace_relationship_or_membership(): void
    {
        $parent = $this->createCustomer()->user;
        $this->createWorkspace($parent);
        $this->createMembership($this->createWorkspace($this->createCustomer()->user), $parent);

        $child = User::create([
            'first_name' => 'Child',
            'last_name' => 'User',
            'email' => 'child' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'parent_id' => $parent->id,
        ]);

        $this->assertCount(0, $child->ownedWorkspaces()->get());
        $this->assertCount(0, $child->workspaceMemberships()->get());
    }
}
