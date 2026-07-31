<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Workspace\WorkspaceMembershipBusinessAssigned;
use App\Events\Workspace\WorkspaceMembershipCreated;
use App\Events\Workspace\WorkspaceMembershipDeactivated;
use App\Events\Workspace\WorkspaceMembershipReactivated;
use App\Events\Workspace\WorkspaceMembershipRoleChanged;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use App\Exceptions\Workspace\OwnerCannotBeMemberException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Exceptions\Workspace\WorkspaceMembershipWorkspaceMismatchException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspaceTransition;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2D: WorkspaceManager::addMember(),
 * changeMemberRole(), deactivateMember(), reactivateMember().
 */
class WorkspaceMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const ALL_MEMBERSHIP_EVENTS = [
        WorkspaceMembershipCreated::class,
        WorkspaceMembershipRoleChanged::class,
        WorkspaceMembershipDeactivated::class,
        WorkspaceMembershipReactivated::class,
        WorkspaceMembershipBusinessAssigned::class,
    ];

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    // --- ADD MEMBER ---

    // 1. Owner can add Admin.
    public function test_owner_can_add_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->manager()->addMember(
            $owner->id,
            $workspace,
            $newMember->id,
            WorkspaceMembershipRole::Admin,
            WorkspaceBusinessAccessScope::All,
        );

        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->role);
        $this->assertTrue($membership->is_active);
    }

    // 2. Owner can add Staff.
    public function test_owner_can_add_staff(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->manager()->addMember(
            $owner->id,
            $workspace,
            $newMember->id,
            WorkspaceMembershipRole::Staff,
            WorkspaceBusinessAccessScope::All,
        );

        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
    }

    // 3. Active Admin can add Staff.
    public function test_active_admin_can_add_staff(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $membership = $this->manager()->addMember(
            $admin->id,
            $workspace,
            $newMember->id,
            WorkspaceMembershipRole::Staff,
            WorkspaceBusinessAccessScope::All,
        );

        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
    }

    // 4. Active Admin cannot add Admin.
    public function test_active_admin_cannot_add_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->addMember($admin->id, $workspace, $newMember->id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
    }

    // 5. Staff cannot add a member.
    public function test_staff_cannot_add_a_member(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->addMember($staff->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
    }

    // 6. Inactive Admin cannot add a member.
    public function test_inactive_admin_cannot_add_a_member(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->addMember($admin->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
    }

    // 7. Unrelated User cannot add a member.
    public function test_unrelated_user_cannot_add_a_member(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->addMember($unrelated->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
    }

    // 8. Cannot add Workspace owner as member.
    public function test_cannot_add_workspace_owner_as_member(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->expectException(OwnerCannotBeMemberException::class);
        $this->manager()->addMember($owner->id, $workspace, $owner->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
    }

    // 9. Cannot add to inactive Workspace.
    public function test_cannot_add_to_inactive_workspace(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
    }

    // 10. All + nonempty IDs is rejected.
    public function test_all_scope_with_nonempty_ids_is_rejected(): void
    {
        $owner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->expectException(InvalidBusinessAccessScopeAssignmentException::class);
        $this->manager()->addMember($owner->user_id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, [$business->id]);
    }

    // 11. Selected + empty IDs succeeds.
    public function test_selected_scope_with_empty_ids_succeeds(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, []);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->business_access_scope);
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 12. Selected + valid IDs creates exact assignments.
    public function test_selected_scope_with_valid_ids_creates_exact_assignments(): void
    {
        $owner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $membership = $this->manager()->addMember(
            $owner->user_id,
            $workspace,
            $newMember->id,
            WorkspaceMembershipRole::Staff,
            WorkspaceBusinessAccessScope::Selected,
            [$businessA->id, $businessB->id],
        );

        $assignedIds = app(WorkspaceMembershipBusinessRepository::class)->assignedBusinessIds($membership)->sort()->values()->all();
        $this->assertSame([$businessA->id, $businessB->id], collect($assignedIds)->sort()->values()->all());
    }

    // 13. Cross-Workspace ID rolls back Membership and all assignments.
    public function test_cross_workspace_id_rolls_back_membership_and_assignments(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $validBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);

        try {
            $this->manager()->addMember(
                $owner->user_id,
                $workspace,
                $newMember->id,
                WorkspaceMembershipRole::Staff,
                WorkspaceBusinessAccessScope::Selected,
                [$validBusiness->id, $foreignBusiness->id],
            );
            $this->fail('Expected CrossWorkspaceAssignmentException was not thrown.');
        } catch (CrossWorkspaceAssignmentException $e) {
            // expected
        }

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $newMember->id)->count());
        $this->assertSame(0, WorkspaceMembershipBusiness::count());
    }

    // 14/15. Exact active match is a no-op; exact-match comparison includes selected Business IDs.
    public function test_exact_active_match_is_a_no_op(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $first = $this->manager()->addMember(
            $owner->user_id, $workspace, $newMember->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        Event::fake(self::ALL_MEMBERSHIP_EVENTS);

        $second = $this->manager()->addMember(
            $owner->user_id, $workspace, $newMember->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $this->assertTrue($first->is($second));
        Event::assertNotDispatched(WorkspaceMembershipCreated::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
    }

    // 16. Active membership with differing role throws.
    public function test_active_membership_with_differing_role_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->expectException(WorkspaceMembershipAlreadyExistsException::class);
        $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
    }

    // 17. Active membership with differing scope throws.
    public function test_active_membership_with_differing_scope_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->expectException(WorkspaceMembershipAlreadyExistsException::class);
        $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, []);
    }

    // 18. Active membership with differing selected IDs throws.
    public function test_active_membership_with_differing_selected_ids_throws(): void
    {
        $owner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $this->manager()->addMember(
            $owner->user_id, $workspace, $newMember->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$businessA->id],
        );

        $this->expectException(WorkspaceMembershipAlreadyExistsException::class);
        $this->manager()->addMember(
            $owner->user_id, $workspace, $newMember->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$businessB->id],
        );
    }

    // 19. Existing inactive membership throws.
    public function test_existing_inactive_membership_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $newMember, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $this->expectException(WorkspaceMembershipAlreadyExistsException::class);
        $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
    }

    // 20. MembershipCreated fires once on creation.
    public function test_membership_created_fires_once(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $membership = $this->manager()->addMember($owner->id, $workspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        Event::assertDispatched(WorkspaceMembershipCreated::class, 1);
        Event::assertDispatched(WorkspaceMembershipCreated::class, function (WorkspaceMembershipCreated $event) use ($membership, $workspace, $newMember, $owner) {
            return $event->membershipId === $membership->id
                && $event->workspaceId === $workspace->id
                && $event->userId === $newMember->id
                && $event->actorUserId === $owner->id
                && $event->role === 'staff'
                && $event->businessAccessScope === 'all';
        });
    }

    // 21. BusinessAssigned fires once per initial selected grant in deterministic order.
    public function test_business_assigned_fires_once_per_grant_in_ascending_order(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $orderedIds = collect([$businessA->id, $businessB->id])->sort()->values()->all();

        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $newMember->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected,
            [$orderedIds[1], $orderedIds[0]], // supplied out of order
        );

        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, 2);

        $dispatchedIds = [];
        foreach (Event::dispatched(WorkspaceMembershipBusinessAssigned::class) as [$event]) {
            $dispatchedIds[] = $event->businessId;
        }
        $this->assertSame($orderedIds, $dispatchedIds);
    }

    // 22. No event fires on exact-match no-op or rollback.
    public function test_no_event_fires_on_rollback(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);

        try {
            $this->manager()->addMember(
                $owner->user_id, $workspace, $newMember->id,
                WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$foreignBusiness->id],
            );
        } catch (CrossWorkspaceAssignmentException) {
            // expected
        }

        Event::assertNotDispatched(WorkspaceMembershipCreated::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
    }

    // --- ROLE CHANGE ---

    // 23. Owner can change role.
    public function test_owner_can_change_role(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $result = $this->manager()->changeMemberRole($owner->id, $membership, WorkspaceMembershipRole::Admin);

        $this->assertSame(WorkspaceMembershipRole::Admin, $result->fresh()->role);
    }

    // 24. Admin cannot change role.
    public function test_admin_cannot_change_role(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->changeMemberRole($admin->id, $membership, WorkspaceMembershipRole::Admin);
    }

    // 25. Staff/unrelated/inactive Admin cannot change role.
    public function test_staff_unrelated_and_inactive_admin_cannot_change_role(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $staff = $this->createCustomer()->user;
        $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $inactiveAdmin = $this->createCustomer()->user;
        $this->createMembership($workspace, $inactiveAdmin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $unrelated = $this->createCustomer()->user;

        foreach ([$staff->id, $inactiveAdmin->id, $unrelated->id] as $actorId) {
            $member = $this->createCustomer()->user;
            $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

            try {
                $this->manager()->changeMemberRole($actorId, $membership, WorkspaceMembershipRole::Admin);
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 26. Inactive Workspace rejects role change.
    public function test_inactive_workspace_rejects_role_change(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);
        $this->manager()->deactivateWorkspace($owner->id, $workspace);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->changeMemberRole($owner->id, $membership, WorkspaceMembershipRole::Admin);
    }

    // 27. Inactive Membership rejects role change.
    public function test_inactive_membership_rejects_role_change(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $this->expectException(InactiveWorkspaceMembershipMutationException::class);
        $this->manager()->changeMemberRole($owner->id, $membership, WorkspaceMembershipRole::Admin);
    }

    // 28. Same-role request is authorized no-op.
    public function test_same_role_request_is_a_no_op(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $result = $this->manager()->changeMemberRole($owner->id, $membership, WorkspaceMembershipRole::Staff);

        $this->assertSame(WorkspaceMembershipRole::Staff, $result->role);
        Event::assertNotDispatched(WorkspaceMembershipRoleChanged::class);
    }

    // 29. Unauthorized same-role request still throws.
    public function test_unauthorized_same_role_request_still_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->changeMemberRole($unrelated->id, $membership, WorkspaceMembershipRole::Staff);
    }

    // 30. Successful change dispatches one event with previous/new role.
    public function test_successful_role_change_dispatches_one_event(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->manager()->changeMemberRole($owner->id, $membership, WorkspaceMembershipRole::Admin);

        Event::assertDispatched(WorkspaceMembershipRoleChanged::class, 1);
        Event::assertDispatched(WorkspaceMembershipRoleChanged::class, function (WorkspaceMembershipRoleChanged $event) use ($membership, $workspace, $member, $owner) {
            return $event->membershipId === $membership->id
                && $event->workspaceId === $workspace->id
                && $event->userId === $member->id
                && $event->actorUserId === $owner->id
                && $event->previousRole === 'staff'
                && $event->newRole === 'admin';
        });
    }

    // --- DEACTIVATE ---

    // 31. Owner can deactivate Admin.
    public function test_owner_can_deactivate_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $result = $this->manager()->deactivateMember($owner->id, $membership);

        $this->assertFalse($result->fresh()->is_active);
    }

    // 32. Owner can deactivate Staff.
    public function test_owner_can_deactivate_staff(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $result = $this->manager()->deactivateMember($owner->id, $membership);

        $this->assertFalse($result->fresh()->is_active);
    }

    // 33. Active Admin can deactivate Staff.
    public function test_active_admin_can_deactivate_staff(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $result = $this->manager()->deactivateMember($admin->id, $membership);

        $this->assertFalse($result->fresh()->is_active);
    }

    // 34. Active Admin cannot deactivate Admin.
    public function test_active_admin_cannot_deactivate_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $adminA = $this->createCustomer()->user;
        $adminB = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $adminA, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $membershipB = $this->createMembership($workspace, $adminB, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->deactivateMember($adminA->id, $membershipB);
    }

    // 35. Staff/unrelated/inactive Admin cannot deactivate.
    public function test_staff_unrelated_and_inactive_admin_cannot_deactivate(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $staff = $this->createCustomer()->user;
        $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $inactiveAdmin = $this->createCustomer()->user;
        $this->createMembership($workspace, $inactiveAdmin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $unrelated = $this->createCustomer()->user;

        foreach ([$staff->id, $inactiveAdmin->id, $unrelated->id] as $actorId) {
            $target = $this->createCustomer()->user;
            $membership = $this->createMembership($workspace, $target, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

            try {
                $this->manager()->deactivateMember($actorId, $membership);
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 36. Inactive Workspace rejects deactivation.
    public function test_inactive_workspace_rejects_deactivation(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);
        $this->manager()->deactivateWorkspace($owner->id, $workspace);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->deactivateMember($owner->id, $membership);
    }

    // 37. Duplicate deactivation is authorized no-op.
    public function test_duplicate_deactivation_is_a_no_op(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $result = $this->manager()->deactivateMember($owner->id, $membership);

        $this->assertFalse($result->is_active);
        Event::assertNotDispatched(WorkspaceMembershipDeactivated::class);
    }

    // 38. Unauthorized duplicate deactivation still throws.
    public function test_unauthorized_duplicate_deactivation_still_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->deactivateMember($unrelated->id, $membership);
    }

    // 39. Selected assignment rows remain after deactivation.
    public function test_selected_assignment_rows_remain_after_deactivation(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $staff->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $this->manager()->deactivateMember($owner->user_id, $membership);

        $this->assertSame(
            1,
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count()
        );
    }

    // 40. Successful deactivation dispatches once.
    public function test_successful_deactivation_dispatches_once(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->manager()->deactivateMember($owner->id, $membership);

        Event::assertDispatched(WorkspaceMembershipDeactivated::class, 1);
        Event::assertDispatched(WorkspaceMembershipDeactivated::class, function (WorkspaceMembershipDeactivated $event) use ($membership, $workspace, $staff, $owner) {
            return $event->membershipId === $membership->id
                && $event->workspaceId === $workspace->id
                && $event->userId === $staff->id
                && $event->actorUserId === $owner->id;
        });
    }

    // --- REACTIVATE ---

    // 41. Owner can reactivate Admin.
    public function test_owner_can_reactivate_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $result = $this->manager()->reactivateMember($owner->id, $membership);

        $this->assertTrue($result->fresh()->is_active);
    }

    // 42. Owner can reactivate Staff.
    public function test_owner_can_reactivate_staff(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $result = $this->manager()->reactivateMember($owner->id, $membership);

        $this->assertTrue($result->fresh()->is_active);
    }

    // 43. Active Admin can reactivate Staff.
    public function test_active_admin_can_reactivate_staff(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $result = $this->manager()->reactivateMember($admin->id, $membership);

        $this->assertTrue($result->fresh()->is_active);
    }

    // 44. Active Admin cannot reactivate Admin.
    public function test_active_admin_cannot_reactivate_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $adminA = $this->createCustomer()->user;
        $adminB = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $adminA, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $membershipB = $this->createMembership($workspace, $adminB, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reactivateMember($adminA->id, $membershipB);
    }

    // 45. Staff/unrelated/inactive Admin cannot reactivate.
    public function test_staff_unrelated_and_inactive_admin_cannot_reactivate(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $staff = $this->createCustomer()->user;
        $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $inactiveAdmin = $this->createCustomer()->user;
        $this->createMembership($workspace, $inactiveAdmin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $unrelated = $this->createCustomer()->user;

        foreach ([$staff->id, $inactiveAdmin->id, $unrelated->id] as $actorId) {
            $target = $this->createCustomer()->user;
            $membership = $this->createMembership($workspace, $target, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

            try {
                $this->manager()->reactivateMember($actorId, $membership);
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 46. Inactive Workspace rejects reactivation.
    public function test_inactive_workspace_rejects_reactivation(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);
        $this->manager()->deactivateWorkspace($owner->id, $workspace);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->reactivateMember($owner->id, $membership);
    }

    // 47. Duplicate reactivation is authorized no-op.
    public function test_duplicate_reactivation_is_a_no_op(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $result = $this->manager()->reactivateMember($owner->id, $membership);

        $this->assertTrue($result->is_active);
        Event::assertNotDispatched(WorkspaceMembershipReactivated::class);
    }

    // 48. Unauthorized duplicate reactivation still throws.
    public function test_unauthorized_duplicate_reactivation_still_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reactivateMember($unrelated->id, $membership);
    }

    // 49. Retained selected assignments become effective again after reactivation.
    public function test_retained_selected_assignments_become_effective_after_reactivation(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $staff->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );
        $this->manager()->deactivateMember($owner->user_id, $membership);

        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));

        $this->manager()->reactivateMember($owner->user_id, $membership);

        $this->assertTrue($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    // 50. Successful reactivation dispatches once.
    public function test_successful_reactivation_dispatches_once(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $this->manager()->reactivateMember($owner->id, $membership);

        Event::assertDispatched(WorkspaceMembershipReactivated::class, 1);
        Event::assertDispatched(WorkspaceMembershipReactivated::class, function (WorkspaceMembershipReactivated $event) use ($membership, $workspace, $staff, $owner) {
            return $event->membershipId === $membership->id
                && $event->workspaceId === $workspace->id
                && $event->userId === $staff->id
                && $event->actorUserId === $owner->id;
        });
    }

    // --- STALE MODEL / BOUNDARY ---

    // 51. Caller-mutated Membership role or is_active state is not trusted.
    public function test_stale_membership_state_is_not_trusted(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $stale = clone $membership;
        $stale->role = WorkspaceMembershipRole::Admin;
        $stale->is_active = false;

        // The owner still succeeds against the real (Staff, active) row,
        // even though the passed-in object claims Admin/inactive.
        $result = $this->manager()->changeMemberRole($owner->id, $stale, WorkspaceMembershipRole::Admin);

        $this->assertSame(WorkspaceMembershipRole::Admin, $result->role);
    }

    // 52. Caller-mutated Workspace owner/is_active state is not trusted.
    public function test_stale_workspace_state_is_not_trusted(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $newMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $staleWorkspace = clone $workspace;
        $staleWorkspace->owner_user_id = $unrelated->id;
        $staleWorkspace->is_active = false;

        // The real owner still succeeds even though the passed-in object
        // claims a different owner and an inactive Workspace.
        $membership = $this->manager()->addMember($owner->id, $staleWorkspace, $newMember->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
    }

    // 53. No workspace_transitions rows are created.
    public function test_no_workspace_transitions_rows_are_created(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $staff->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );
        $this->manager()->changeMemberRole($owner->user_id, $membership, WorkspaceMembershipRole::Admin);
        $this->manager()->deactivateMember($owner->user_id, $membership);
        $this->manager()->reactivateMember($owner->user_id, $membership);

        $this->assertSame(0, WorkspaceTransition::count());
    }

    // --- WORKSPACE/MEMBERSHIP CONSISTENCY (Slice 2D correction) ---

    // 54. changeMemberRole rejects a Membership whose ID belongs to
    // Workspace B but whose in-memory workspace_id is mutated to
    // Workspace A. Authority from Workspace A (owner of A) cannot be used
    // to mutate a Membership that actually belongs to Workspace B.
    public function test_change_member_role_rejects_workspace_membership_mismatch(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($ownerA);
        $workspaceB = $this->createWorkspace($ownerB);
        $membership = $this->createMembership($workspaceB, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $mutated = clone $membership;
        $mutated->workspace_id = $workspaceA->id;

        try {
            $this->manager()->changeMemberRole($ownerA->id, $mutated, WorkspaceMembershipRole::Admin);
            $this->fail('Expected WorkspaceMembershipWorkspaceMismatchException was not thrown.');
        } catch (WorkspaceMembershipWorkspaceMismatchException $e) {
            $this->assertSame($membership->id, $e->membershipId);
            $this->assertSame($workspaceA->id, $e->expectedWorkspaceId);
            $this->assertSame($workspaceB->id, $e->actualWorkspaceId);
        }

        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->fresh()->role);
        Event::assertNotDispatched(WorkspaceMembershipRoleChanged::class);
    }

    // 55. deactivateMember rejects the same Workspace/Membership mismatch.
    public function test_deactivate_member_rejects_workspace_membership_mismatch(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($ownerA);
        $workspaceB = $this->createWorkspace($ownerB);
        $membership = $this->createMembership($workspaceB, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $mutated = clone $membership;
        $mutated->workspace_id = $workspaceA->id;

        try {
            $this->manager()->deactivateMember($ownerA->id, $mutated);
            $this->fail('Expected WorkspaceMembershipWorkspaceMismatchException was not thrown.');
        } catch (WorkspaceMembershipWorkspaceMismatchException $e) {
            $this->assertSame($membership->id, $e->membershipId);
            $this->assertSame($workspaceA->id, $e->expectedWorkspaceId);
            $this->assertSame($workspaceB->id, $e->actualWorkspaceId);
        }

        $this->assertTrue($membership->fresh()->is_active);
        Event::assertNotDispatched(WorkspaceMembershipDeactivated::class);
    }

    // 56. reactivateMember rejects the same Workspace/Membership mismatch.
    public function test_reactivate_member_rejects_workspace_membership_mismatch(): void
    {
        Event::fake(self::ALL_MEMBERSHIP_EVENTS);
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($ownerA);
        $workspaceB = $this->createWorkspace($ownerB);
        $membership = $this->createMembership($workspaceB, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $mutated = clone $membership;
        $mutated->workspace_id = $workspaceA->id;

        try {
            $this->manager()->reactivateMember($ownerA->id, $mutated);
            $this->fail('Expected WorkspaceMembershipWorkspaceMismatchException was not thrown.');
        } catch (WorkspaceMembershipWorkspaceMismatchException $e) {
            $this->assertSame($membership->id, $e->membershipId);
            $this->assertSame($workspaceA->id, $e->expectedWorkspaceId);
            $this->assertSame($workspaceB->id, $e->actualWorkspaceId);
        }

        $this->assertFalse($membership->fresh()->is_active);
        Event::assertNotDispatched(WorkspaceMembershipReactivated::class);
    }

    // 57. No Slice 2E/2F/2G methods are added.
    public function test_no_later_milestone_2_methods_exist(): void
    {
        foreach ([
            'changeMemberBusinessAccessScope',
            'assignBusinessToMember',
            'unassignBusinessFromMember',
            'createBusinessInWorkspace',
            'reassignBusiness',
            'transferOwnership',
        ] as $method) {
            $this->assertFalse(
                method_exists(WorkspaceManager::class, $method),
                "Unexpected method [{$method}] found on WorkspaceManager."
            );
        }
    }
}
