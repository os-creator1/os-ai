<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Workspace\WorkspaceMembershipBusinessAccessScopeChanged;
use App\Events\Workspace\WorkspaceMembershipBusinessAssigned;
use App\Events\Workspace\WorkspaceMembershipBusinessUnassigned;
use App\Exceptions\Workspace\BusinessAssignmentRequiresSelectedScopeException;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceMembershipWorkspaceMismatchException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspaceTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2E: WorkspaceManager::
 * changeMemberBusinessAccessScope(), assignBusinessToMember(),
 * unassignBusinessFromMember().
 */
class WorkspaceMembershipBusinessAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const ALL_EVENTS = [
        WorkspaceMembershipBusinessAccessScopeChanged::class,
        WorkspaceMembershipBusinessAssigned::class,
        WorkspaceMembershipBusinessUnassigned::class,
    ];

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    private function assertThrowsForAllThreeMethods(
        string $exceptionClass,
        int $actorId,
        WorkspaceMembership $membership,
        Business $business,
    ): void {
        try {
            $this->manager()->changeMemberBusinessAccessScope($actorId, $membership, WorkspaceBusinessAccessScope::All, []);
            $this->fail("Expected {$exceptionClass} from changeMemberBusinessAccessScope().");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exceptionClass, $e);
        }

        try {
            $this->manager()->assignBusinessToMember($actorId, $membership, $business);
            $this->fail("Expected {$exceptionClass} from assignBusinessToMember().");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exceptionClass, $e);
        }

        try {
            $this->manager()->unassignBusinessFromMember($actorId, $membership, $business);
            $this->fail("Expected {$exceptionClass} from unassignBusinessFromMember().");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exceptionClass, $e);
        }
    }

    // --- AUTHORITY AND STATE ---

    // 1. Owner may change scope.
    public function test_owner_may_change_scope(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $result = $this->manager()->changeMemberBusinessAccessScope($owner->id, $membership, WorkspaceBusinessAccessScope::Selected, []);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $result->business_access_scope);
    }

    // 2. Active Admin may change scope.
    public function test_active_admin_may_change_scope(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $result = $this->manager()->changeMemberBusinessAccessScope($admin->id, $membership, WorkspaceBusinessAccessScope::Selected, []);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $result->business_access_scope);
    }

    // 3-5. Staff, inactive Admin, and unrelated User may not change scope.
    public function test_staff_inactive_admin_and_unrelated_user_may_not_change_scope(): void
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
            $membership = $this->createMembership($workspace, $target, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

            try {
                $this->manager()->changeMemberBusinessAccessScope($actorId, $membership, WorkspaceBusinessAccessScope::Selected, []);
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 6. Inactive Workspace rejects all three methods.
    public function test_inactive_workspace_rejects_all_three_methods(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );
        $this->manager()->deactivateWorkspace($owner->user_id, $workspace);

        $this->assertThrowsForAllThreeMethods(InactiveWorkspaceMutationException::class, $owner->user_id, $membership, $business);
    }

    // 7. Inactive Membership rejects all three methods.
    public function test_inactive_membership_rejects_all_three_methods(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );
        $this->manager()->deactivateMember($owner->user_id, $membership);

        $this->assertThrowsForAllThreeMethods(InactiveWorkspaceMembershipMutationException::class, $owner->user_id, $membership, $business);
    }

    // 8. Workspace/Membership mismatch rejects all three methods.
    public function test_workspace_membership_mismatch_rejects_all_three_methods(): void
    {
        $ownerA = $this->createCustomer()->user;
        $ownerB = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($ownerA);
        $workspaceB = $this->createWorkspace($ownerB->user);
        $business = $this->createBusinessForCustomer($ownerB->user_id, $workspaceB->id);
        $membership = $this->manager()->addMember(
            $ownerB->user_id, $workspaceB, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $mutated = clone $membership;
        $mutated->workspace_id = $workspaceA->id;

        $this->assertThrowsForAllThreeMethods(WorkspaceMembershipWorkspaceMismatchException::class, $ownerA->id, $mutated, $business);
    }

    // 9. Caller-mutated Workspace state (a stale cached relation) is not
    // trusted — the real owner still succeeds even though the passed-in
    // Membership's cached workspace relation claims a different owner and
    // an inactive Workspace, because the Manager always re-fetches the
    // Workspace via repository by ID rather than reading this relation.
    public function test_caller_mutated_workspace_relation_is_not_trusted(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $staleWorkspace = clone $workspace;
        $staleWorkspace->is_active = false;
        $staleWorkspace->owner_user_id = $unrelated->id;
        $membership->setRelation('workspace', $staleWorkspace);

        $result = $this->manager()->changeMemberBusinessAccessScope($owner->id, $membership, WorkspaceBusinessAccessScope::Selected, []);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $result->business_access_scope);
    }

    // 10. Caller-mutated Membership role/scope/active state is not trusted.
    public function test_caller_mutated_membership_state_is_not_trusted(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $stale = clone $membership;
        $stale->is_active = false;
        $stale->business_access_scope = WorkspaceBusinessAccessScope::Selected;

        // The owner still succeeds against the real (active, All) row, even
        // though the passed-in object claims inactive/Selected.
        $result = $this->manager()->changeMemberBusinessAccessScope($owner->id, $stale, WorkspaceBusinessAccessScope::Selected, []);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $result->business_access_scope);
    }

    // --- SCOPE PAYLOAD ---

    // 11. Selected + [] succeeds.
    public function test_selected_scope_with_empty_ids_succeeds(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $result = $this->manager()->changeMemberBusinessAccessScope($owner->id, $membership, WorkspaceBusinessAccessScope::Selected, []);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $result->business_access_scope);
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 12. All + nonempty IDs is rejected.
    public function test_all_scope_with_nonempty_ids_is_rejected(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $this->expectException(InvalidBusinessAccessScopeAssignmentException::class);
        $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::All, [$business->id]);
    }

    // 13. IDs are normalized.
    public function test_ids_are_normalized(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$businessB->id, $businessA->id, $businessB->id, (string) $businessA->id],
        );

        $persistedIds = WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->pluck('business_id')->sort()->values()->all();
        $this->assertSame(collect([$businessA->id, $businessB->id])->sort()->values()->all(), $persistedIds);
    }

    // 14. Cross-Workspace IDs roll back every change.
    public function test_cross_workspace_ids_roll_back_every_change(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $validBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        Event::fake(self::ALL_EVENTS);

        try {
            $this->manager()->changeMemberBusinessAccessScope(
                $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
                [$validBusiness->id, $foreignBusiness->id],
            );
            $this->fail('Expected CrossWorkspaceAssignmentException was not thrown.');
        } catch (CrossWorkspaceAssignmentException $e) {
            // expected
        }

        $this->assertSame(WorkspaceBusinessAccessScope::All, $membership->fresh()->business_access_scope);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
    }

    // 15. Mixed valid/invalid IDs produce no partial assignments.
    public function test_mixed_valid_and_invalid_ids_produce_no_partial_assignments(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $validBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        try {
            $this->manager()->changeMemberBusinessAccessScope(
                $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
                [$validBusiness->id, $foreignBusiness->id],
            );
        } catch (CrossWorkspaceAssignmentException) {
            // expected
        }

        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // --- TRANSITIONS ---

    // 16. All -> All is no-op.
    public function test_all_to_all_is_a_no_op(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        Event::fake(self::ALL_EVENTS);

        $result = $this->manager()->changeMemberBusinessAccessScope($owner->id, $membership, WorkspaceBusinessAccessScope::All, []);

        $this->assertSame(WorkspaceBusinessAccessScope::All, $result->business_access_scope);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
    }

    // 17. All -> Selected with [] succeeds and emits only scope-changed.
    public function test_all_to_selected_with_empty_ids_emits_only_scope_changed(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->changeMemberBusinessAccessScope($owner->id, $membership, WorkspaceBusinessAccessScope::Selected, []);

        Event::assertDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class, 1);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
    }

    // 18. All -> Selected with IDs writes exact set.
    public function test_all_to_selected_with_ids_writes_exact_set(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$businessA->id, $businessB->id],
        );

        $persistedIds = WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->pluck('business_id')->sort()->values()->all();
        $this->assertSame(collect([$businessA->id, $businessB->id])->sort()->values()->all(), $persistedIds);
    }

    // 19. Event order is scope-changed then assigned IDs ascending.
    public function test_all_to_selected_event_order_is_scope_changed_then_assigned_ascending(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $orderedIds = collect([$businessA->id, $businessB->id])->sort()->values()->all();
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $order = [];
        Event::listen(WorkspaceMembershipBusinessAccessScopeChanged::class, function () use (&$order) {
            $order[] = 'scope_changed';
        });
        Event::listen(WorkspaceMembershipBusinessAssigned::class, function (WorkspaceMembershipBusinessAssigned $event) use (&$order) {
            $order[] = "assigned:{$event->businessId}";
        });

        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$orderedIds[1], $orderedIds[0]], // supplied out of order
        );

        $this->assertSame(['scope_changed', "assigned:{$orderedIds[0]}", "assigned:{$orderedIds[1]}"], $order);
    }

    // 20. Selected -> All clears all rows.
    public function test_selected_to_all_clears_all_rows(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $result = $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::All, []);

        $this->assertSame(WorkspaceBusinessAccessScope::All, $result->business_access_scope);
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 21. Event order is unassigned IDs ascending then scope-changed.
    public function test_selected_to_all_event_order_is_unassigned_ascending_then_scope_changed(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $orderedIds = collect([$businessA->id, $businessB->id])->sort()->values()->all();
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, $orderedIds,
        );

        $order = [];
        Event::listen(WorkspaceMembershipBusinessUnassigned::class, function (WorkspaceMembershipBusinessUnassigned $event) use (&$order) {
            $order[] = "unassigned:{$event->businessId}";
        });
        Event::listen(WorkspaceMembershipBusinessAccessScopeChanged::class, function () use (&$order) {
            $order[] = 'scope_changed';
        });

        $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::All, []);

        $this->assertSame(["unassigned:{$orderedIds[0]}", "unassigned:{$orderedIds[1]}", 'scope_changed'], $order);
    }

    // 22. Selected -> Selected identical normalized set is no-op.
    public function test_selected_to_selected_identical_set_is_a_no_op(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        Event::fake(self::ALL_EVENTS);

        // Same set, supplied with a duplicate — proves normalization feeds
        // the no-op comparison too.
        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$business->id, $business->id],
        );

        Event::assertNotDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 23. Selected -> Selected changed set writes exact result.
    public function test_selected_to_selected_changed_set_writes_exact_result(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessC = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$businessA->id, $businessB->id],
        );

        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$businessB->id, $businessC->id],
        );

        $persistedIds = WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->pluck('business_id')->sort()->values()->all();
        $this->assertSame(collect([$businessB->id, $businessC->id])->sort()->values()->all(), $persistedIds);
    }

    // 24. Only set-difference assignment events fire.
    public function test_selected_to_selected_only_set_difference_events_fire(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessC = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$businessA->id, $businessB->id],
        );

        Event::fake(self::ALL_EVENTS);

        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$businessB->id, $businessC->id],
        );

        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, 1);
        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, fn (WorkspaceMembershipBusinessAssigned $e) => $e->businessId === $businessC->id);
        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, 1);
        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, fn (WorkspaceMembershipBusinessUnassigned $e) => $e->businessId === $businessA->id);
    }

    // 25. No scope-changed event fires for Selected -> Selected.
    public function test_no_scope_changed_event_fires_for_selected_to_selected(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$businessA->id],
        );

        Event::fake(self::ALL_EVENTS);

        $this->manager()->changeMemberBusinessAccessScope(
            $owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected,
            [$businessB->id],
        );

        Event::assertNotDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class);
    }

    // --- DIRECT ASSIGN ---

    // 26. Selected-scope assignment succeeds.
    public function test_selected_scope_direct_assignment_succeeds(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $assignment = $this->manager()->assignBusinessToMember($owner->user_id, $membership, $business);

        $this->assertSame($business->id, $assignment->business_id);
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->where('business_id', $business->id)->count());
    }

    // 27. All-scope assignment throws.
    public function test_all_scope_direct_assignment_throws(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->expectException(BusinessAssignmentRequiresSelectedScopeException::class);
        $this->manager()->assignBusinessToMember($owner->user_id, $membership, $business);
    }

    // 28. Cross-Workspace Business throws.
    public function test_cross_workspace_business_direct_assignment_throws(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $this->expectException(CrossWorkspaceAssignmentException::class);
        $this->manager()->assignBusinessToMember($owner->user_id, $membership, $foreignBusiness);
    }

    // 29. Existing assignment is no-op and returns the persisted assignment.
    public function test_existing_assignment_is_a_no_op_and_returns_persisted_assignment(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );
        $existing = WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->where('business_id', $business->id)->firstOrFail();

        $result = $this->manager()->assignBusinessToMember($owner->user_id, $membership, $business);

        $this->assertTrue($result->is($existing));
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 30. Successful assignment dispatches once.
    public function test_successful_direct_assignment_dispatches_once(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->assignBusinessToMember($owner->user_id, $membership, $business);

        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, 1);
        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, function (WorkspaceMembershipBusinessAssigned $event) use ($membership, $workspace, $business, $owner) {
            return $event->membershipId === $membership->id
                && $event->workspaceId === $workspace->id
                && $event->businessId === $business->id
                && $event->actorUserId === $owner->user_id;
        });
    }

    // 31. No event on duplicate or rollback.
    public function test_no_event_on_duplicate_or_rollback_direct_assignment(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        Event::fake(self::ALL_EVENTS);

        $this->manager()->assignBusinessToMember($owner->user_id, $membership, $business);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);

        try {
            $this->manager()->assignBusinessToMember($owner->user_id, $membership, $foreignBusiness);
        } catch (CrossWorkspaceAssignmentException) {
            // expected
        }
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
    }

    // Review correction: assignBusinessToMember() must ignore a caller-
    // mutated in-memory Business.workspace_id and use the authoritative
    // persisted Business row instead. The mismatch is constructed only on
    // the caller-side clone — the real, persisted Business row is never
    // touched and stays in Workspace A throughout.
    public function test_assign_ignores_caller_mutated_business_workspace_id(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($owner->user);
        $workspaceB = $this->createWorkspace($otherOwner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspaceA->id);
        $membership = $this->createMembership($workspaceA, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $mutated = clone $business;
        $mutated->workspace_id = $workspaceB->id;

        Event::fake(self::ALL_EVENTS);

        $assignment = $this->manager()->assignBusinessToMember($owner->user_id, $membership, $mutated);

        $this->assertSame($business->id, $assignment->business_id);
        $this->assertSame(
            1,
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->where('business_id', $business->id)->count()
        );
        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, 1);
        $this->assertSame($workspaceA->id, $business->fresh()->workspace_id);
        $this->assertSame(
            0,
            WorkspaceMembershipBusiness::whereHas('membership', fn ($query) => $query->where('workspace_id', $workspaceB->id))->count()
        );
        $this->assertSame(0, WorkspaceTransition::count());
    }

    // --- DIRECT UNASSIGN ---

    // 32. Selected-scope unassignment succeeds.
    public function test_selected_scope_direct_unassignment_succeeds(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $business);

        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 33. All-scope unassignment throws.
    public function test_all_scope_direct_unassignment_throws(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->expectException(BusinessAssignmentRequiresSelectedScopeException::class);
        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $business);
    }

    // 34. Cross-Workspace Business throws.
    public function test_cross_workspace_business_direct_unassignment_throws(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $this->expectException(CrossWorkspaceAssignmentException::class);
        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $foreignBusiness);
    }

    // 35. Missing assignment is no-op.
    public function test_missing_assignment_direct_unassignment_is_a_no_op(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $business);

        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 36. Successful unassignment dispatches once.
    public function test_successful_direct_unassignment_dispatches_once(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        Event::fake(self::ALL_EVENTS);

        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $business);

        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, 1);
        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, function (WorkspaceMembershipBusinessUnassigned $event) use ($membership, $workspace, $business, $owner) {
            return $event->membershipId === $membership->id
                && $event->workspaceId === $workspace->id
                && $event->businessId === $business->id
                && $event->actorUserId === $owner->user_id;
        });
    }

    // 37. No event on missing grant or rollback.
    public function test_no_event_on_missing_grant_or_rollback_direct_unassignment(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $business);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);

        try {
            $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $foreignBusiness);
        } catch (CrossWorkspaceAssignmentException) {
            // expected
        }
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
    }

    // Review correction: unassignBusinessFromMember() must ignore a
    // caller-mutated in-memory Business.workspace_id and use the
    // authoritative persisted Business row instead. The mismatch is
    // constructed only on the caller-side clone — the real, persisted
    // Business row is never touched and stays in Workspace A throughout.
    public function test_unassign_ignores_caller_mutated_business_workspace_id(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspaceA = $this->createWorkspace($owner->user);
        $workspaceB = $this->createWorkspace($otherOwner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspaceA->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspaceA, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $mutated = clone $business;
        $mutated->workspace_id = $workspaceB->id;

        Event::fake(self::ALL_EVENTS);

        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $mutated);

        $this->assertSame(
            0,
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->where('business_id', $business->id)->count()
        );
        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, 1);
        $this->assertSame($workspaceA->id, $business->fresh()->workspace_id);
        $this->assertSame(0, WorkspaceTransition::count());
    }

    // --- REGRESSION ---

    // 38. Assignment rows survive membership deactivate/reactivate.
    public function test_assignment_rows_survive_deactivate_and_reactivate(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->manager()->addMember(
            $owner->user_id, $workspace, $member->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $this->manager()->deactivateMember($owner->user_id, $membership);
        $this->manager()->reactivateMember($owner->user_id, $membership);

        $this->assertSame(
            1,
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->where('business_id', $business->id)->count()
        );
    }

    // 39. userCanAccessBusiness reflects every scope/assignment change
    // immediately.
    public function test_user_can_access_business_reflects_scope_and_assignment_changes_immediately(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->assertTrue($this->manager()->userCanAccessBusiness($member->id, $businessA));
        $this->assertTrue($this->manager()->userCanAccessBusiness($member->id, $businessB));

        $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected, [$businessA->id]);

        $this->assertTrue($this->manager()->userCanAccessBusiness($member->id, $businessA));
        $this->assertFalse($this->manager()->userCanAccessBusiness($member->id, $businessB));

        $this->manager()->assignBusinessToMember($owner->user_id, $membership, $businessB);

        $this->assertTrue($this->manager()->userCanAccessBusiness($member->id, $businessB));

        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $businessA);

        $this->assertFalse($this->manager()->userCanAccessBusiness($member->id, $businessA));

        $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::All, []);

        $this->assertTrue($this->manager()->userCanAccessBusiness($member->id, $businessA));
    }

    // 40. No workspace_transitions rows are created.
    public function test_no_workspace_transitions_rows_are_created(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $member, ['business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::Selected, [$business->id]);
        $this->manager()->assignBusinessToMember($owner->user_id, $membership, $business);
        $this->manager()->unassignBusinessFromMember($owner->user_id, $membership, $business);
        $this->manager()->changeMemberBusinessAccessScope($owner->user_id, $membership, WorkspaceBusinessAccessScope::All, []);

        $this->assertSame(0, WorkspaceTransition::count());
    }

    // 41. No Slice 2G public method is added. Slice 2F's own methods
    // (createBusinessInWorkspace(), reassignBusiness()) are removed from
    // this forbidden list now that Slice 2F implements them — covered
    // instead by WorkspaceBusinessOrchestrationTest's own later-milestone
    // boundary assertion.
    public function test_no_later_milestone_2_methods_exist(): void
    {
        foreach ([
            'transferOwnership',
        ] as $method) {
            $this->assertFalse(
                method_exists(WorkspaceManager::class, $method),
                "Unexpected method [{$method}] found on WorkspaceManager."
            );
        }
    }
}
