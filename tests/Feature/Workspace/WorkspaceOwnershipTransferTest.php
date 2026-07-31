<?php

namespace Tests\Feature\Workspace;

use App\DTO\Workspace\WorkspaceOwnershipTransferDisposition;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Workspace\WorkspaceTransitionType;
use App\Events\Workspace\WorkspaceMembershipBusinessAccessScopeChanged;
use App\Events\Workspace\WorkspaceMembershipBusinessAssigned;
use App\Events\Workspace\WorkspaceMembershipBusinessUnassigned;
use App\Events\Workspace\WorkspaceMembershipCreated;
use App\Events\Workspace\WorkspaceMembershipDeactivated;
use App\Events\Workspace\WorkspaceMembershipReactivated;
use App\Events\Workspace\WorkspaceMembershipRoleChanged;
use App\Events\Workspace\WorkspaceOwnershipTransferred;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceInvalidIncomingOwnerException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspaceTransition;
use App\Repositories\Contracts\WorkspaceTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2G: WorkspaceManager::transferOwnership().
 */
class WorkspaceOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const ALL_EVENTS = [
        WorkspaceMembershipDeactivated::class,
        WorkspaceMembershipReactivated::class,
        WorkspaceMembershipCreated::class,
        WorkspaceMembershipRoleChanged::class,
        WorkspaceMembershipBusinessAccessScopeChanged::class,
        WorkspaceMembershipBusinessAssigned::class,
        WorkspaceMembershipBusinessUnassigned::class,
        WorkspaceOwnershipTransferred::class,
    ];

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    /**
     * @return array<int, array{sql: string, bindings: array<int, mixed>}>
     */
    private function captureQueries(\Closure $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $callback();

        return $queries;
    }

    private function createAnomalousPreviousOwnerMembership(Workspace $workspace, int $userId, array $overrides = []): WorkspaceMembership
    {
        return WorkspaceMembership::create(array_merge([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ], $overrides));
    }

    // --- AUTHORITY AND BASIC STATE ---

    // 1. Current owner may transfer.
    public function test_current_owner_may_transfer(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $result = $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertSame($newOwner->id, $result->owner_user_id);
    }

    // 2-5. Active Admin, Staff, inactive Admin, and unrelated User may not transfer.
    public function test_active_admin_staff_inactive_admin_and_unrelated_user_may_not_transfer(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $activeAdmin = $this->createCustomer()->user;
        $this->createMembership($workspace, $activeAdmin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $staff = $this->createCustomer()->user;
        $this->createMembership($workspace, $staff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $inactiveAdmin = $this->createCustomer()->user;
        $this->createMembership($workspace, $inactiveAdmin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $unrelated = $this->createCustomer()->user;

        foreach ([$activeAdmin->id, $staff->id, $inactiveAdmin->id, $unrelated->id] as $actorId) {
            try {
                $this->manager()->transferOwnership($actorId, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 6. Inactive Workspace rejects transfer.
    public function test_inactive_workspace_rejects_transfer(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());
    }

    // 7. Missing Workspace throws WorkspaceNotFoundException.
    public function test_missing_workspace_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $phantom = new Workspace(['name' => 'Phantom', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $phantom->id = 999999999;

        $this->expectException(WorkspaceNotFoundException::class);
        $this->manager()->transferOwnership($owner->id, $phantom, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());
    }

    // 8. Missing incoming User throws WorkspaceInvalidIncomingOwnerException.
    public function test_missing_incoming_user_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->expectException(WorkspaceInvalidIncomingOwnerException::class);
        $this->manager()->transferOwnership($owner->id, $workspace, 999999999, WorkspaceOwnershipTransferDisposition::deactivate());
    }

    // 9. Caller-mutated Workspace owner/is_active state is ignored.
    public function test_caller_mutated_workspace_state_is_ignored(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $stale = clone $workspace;
        $stale->is_active = false;
        $stale->owner_user_id = $unrelated->id;

        $result = $this->manager()->transferOwnership($owner->id, $stale, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertSame($newOwner->id, $result->owner_user_id);
    }

    // 10. Same-owner transfer is an authorized no-op.
    public function test_same_owner_transfer_is_a_no_op(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        Event::fake(self::ALL_EVENTS);

        $result = $this->manager()->transferOwnership($owner->id, $workspace, $owner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertSame($owner->id, $result->owner_user_id);
        Event::assertNotDispatched(WorkspaceOwnershipTransferred::class);
        $this->assertSame(0, WorkspaceTransition::count());
    }

    // 11. Unauthorized same-owner attempt still throws.
    public function test_unauthorized_same_owner_attempt_still_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->transferOwnership($unrelated->id, $workspace, $owner->id, WorkspaceOwnershipTransferDisposition::deactivate());
    }

    // --- LOCKING ---

    // 12-15. Workspace locked first; both User rows locked ascending; both
    // membership rows locked ascending, after the User locks.
    public function test_workspace_then_users_then_memberships_locked_in_ascending_order(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        [$lowUserId, $highUserId] = collect([$owner->id, $newOwner->id])->sort()->values()->all();

        $queries = $this->captureQueries(function () use ($owner, $workspace, $newOwner) {
            $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());
        });

        $lockQueries = array_values(array_filter($queries, fn ($q) => str_contains(strtolower($q['sql']), 'for update')));

        $this->assertGreaterThanOrEqual(5, count($lockQueries));

        $this->assertStringContainsString('`workspaces`', $lockQueries[0]['sql']);
        $this->assertSame([$workspace->id], $lockQueries[0]['bindings']);

        $this->assertStringContainsString('`users`', $lockQueries[1]['sql']);
        $this->assertSame([$lowUserId], $lockQueries[1]['bindings']);
        $this->assertStringContainsString('`users`', $lockQueries[2]['sql']);
        $this->assertSame([$highUserId], $lockQueries[2]['bindings']);

        $this->assertStringContainsString('`workspace_memberships`', $lockQueries[3]['sql']);
        $this->assertSame([$workspace->id, $lowUserId], $lockQueries[3]['bindings']);
        $this->assertStringContainsString('`workspace_memberships`', $lockQueries[4]['sql']);
        $this->assertSame([$workspace->id, $highUserId], $lockQueries[4]['bindings']);
    }

    // 16. No ownership/membership write occurs before all required locks
    // and validation.
    public function test_no_write_occurs_before_locks_and_validation_complete(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        try {
            $this->manager()->transferOwnership($owner->id, $workspace, 999999999, WorkspaceOwnershipTransferDisposition::deactivate());
            $this->fail('Expected WorkspaceInvalidIncomingOwnerException.');
        } catch (WorkspaceInvalidIncomingOwnerException) {
            // expected
        }

        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
    }

    // --- INCOMING OWNER ---

    // 17. Existing active incoming membership is deactivated.
    public function test_existing_active_incoming_membership_is_deactivated(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => true]);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertFalse($incomingMembership->fresh()->is_active);
    }

    // 18. Existing inactive incoming membership remains inactive with no event.
    public function test_existing_inactive_incoming_membership_remains_inactive_with_no_event(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => false]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertFalse($incomingMembership->fresh()->is_active);
        Event::assertNotDispatched(WorkspaceMembershipDeactivated::class);
    }

    // 19. Incoming membership is never deleted.
    public function test_incoming_membership_is_never_deleted(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => true]);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertNotNull(WorkspaceMembership::find($incomingMembership->id));
    }

    // 20. Incoming selected assignment rows are retained.
    public function test_incoming_selected_assignment_rows_are_retained(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $incomingMembership = $this->manager()->addMember(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id],
        );

        $this->manager()->transferOwnership($owner->user_id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $incomingMembership->id)->count());
    }

    // 21. Incoming deactivation event fires only on actual state change.
    public function test_incoming_deactivation_event_fires_only_on_actual_change(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => true]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        Event::assertDispatched(WorkspaceMembershipDeactivated::class, 1);
        Event::assertDispatched(WorkspaceMembershipDeactivated::class, function (WorkspaceMembershipDeactivated $event) use ($incomingMembership, $workspace, $newOwner, $owner) {
            return $event->membershipId === $incomingMembership->id
                && $event->workspaceId === $workspace->id
                && $event->userId === $newOwner->id
                && $event->actorUserId === $owner->id;
        });
    }

    // --- PREVIOUS OWNER: DEACTIVATE ---

    // 22. No membership row remains valid and no row is created.
    public function test_deactivate_disposition_creates_no_row_when_none_existed(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertNull(WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->first());
    }

    // 23. Existing active membership is deactivated (anomalous pre-existing
    // owner-membership row, §7.3 not always holding for historical data).
    public function test_deactivate_disposition_deactivates_existing_active_previous_owner_membership(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $anomalous = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id, ['is_active' => true]);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertFalse($anomalous->fresh()->is_active);
    }

    // 24. Existing inactive membership is unchanged.
    public function test_deactivate_disposition_leaves_existing_inactive_previous_owner_membership_unchanged(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $anomalous = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id, ['is_active' => false]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertFalse($anomalous->fresh()->is_active);
        Event::assertNotDispatched(WorkspaceMembershipDeactivated::class);
    }

    // 25. Assignment rows are retained.
    public function test_deactivate_disposition_retains_previous_owner_assignment_rows(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $anomalous = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->user_id, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $anomalous->id, 'business_id' => $business->id]);

        $this->manager()->transferOwnership($owner->user_id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $anomalous->id)->count());
    }

    // 26. Only actual deactivation emits an event.
    public function test_deactivate_disposition_only_actual_state_changes_emit_events(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $newOwner, ['is_active' => true]);
        $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id, ['is_active' => true]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        Event::assertDispatched(WorkspaceMembershipDeactivated::class, 2);
    }

    // --- PREVIOUS OWNER: CONVERT TO ADMIN, NEW ROW ---

    // 27. Missing row creates active Admin membership.
    public function test_convert_to_admin_creates_active_admin_membership_when_missing(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->role);
        $this->assertTrue($membership->is_active);
    }

    // 28. All scope creates no assignments.
    public function test_convert_to_admin_all_scope_creates_no_assignments(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->first();
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 29. Selected + [] succeeds.
    public function test_convert_to_admin_selected_scope_with_empty_ids_succeeds(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, []),
        );

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->first();
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->business_access_scope);
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    // 30. Selected + IDs creates exact normalized assignments.
    public function test_convert_to_admin_selected_scope_with_ids_creates_exact_assignments(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->manager()->transferOwnership(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, [$businessB->id, $businessA->id, $businessB->id]),
        );

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->user_id)->first();
        $persistedIds = WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->pluck('business_id')->sort()->values()->all();
        $this->assertSame(collect([$businessA->id, $businessB->id])->sort()->values()->all(), $persistedIds);
    }

    // 31. Created event fires before assignment events.
    public function test_created_event_fires_before_assignment_events_for_new_row(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $orderedIds = collect([$businessA->id, $businessB->id])->sort()->values()->all();

        $order = [];
        Event::listen(WorkspaceMembershipCreated::class, function () use (&$order) {
            $order[] = 'created';
        });
        Event::listen(WorkspaceMembershipBusinessAssigned::class, function (WorkspaceMembershipBusinessAssigned $event) use (&$order) {
            $order[] = "assigned:{$event->businessId}";
        });

        $this->manager()->transferOwnership(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, [$orderedIds[1], $orderedIds[0]]),
        );

        $this->assertSame(['created', "assigned:{$orderedIds[0]}", "assigned:{$orderedIds[1]}"], $order);
    }

    // 32. No role/scope-change event fires for a newly-created row.
    public function test_no_role_or_scope_change_event_fires_for_newly_created_row(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        Event::assertNotDispatched(WorkspaceMembershipRoleChanged::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class);
    }

    // --- PREVIOUS OWNER: CONVERT TO ADMIN, EXISTING ROW ---

    // 33. Inactive row is reactivated.
    public function test_convert_to_admin_reactivates_inactive_existing_row(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $existing = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        $this->assertTrue($existing->fresh()->is_active);
        Event::assertDispatched(WorkspaceMembershipReactivated::class, 1);
    }

    // 34. Non-Admin role is changed to Admin.
    public function test_convert_to_admin_changes_non_admin_role_to_admin(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $existing = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        $this->assertSame(WorkspaceMembershipRole::Admin, $existing->fresh()->role);
        Event::assertDispatched(WorkspaceMembershipRoleChanged::class, 1);
    }

    // 35. Different scope is updated.
    public function test_convert_to_admin_updates_different_scope(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $existing = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->user_id, [
            'role' => WorkspaceMembershipRole::Admin,
        ]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, []),
        );

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $existing->fresh()->business_access_scope);
        Event::assertDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class, 1);
    }

    // 36. Same role/scope produces no redundant event.
    public function test_convert_to_admin_same_role_and_scope_produces_no_redundant_event(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id, ['role' => WorkspaceMembershipRole::Admin]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        Event::assertNotDispatched(WorkspaceMembershipRoleChanged::class);
        Event::assertNotDispatched(WorkspaceMembershipReactivated::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAccessScopeChanged::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessAssigned::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
    }

    // 37. Selected assignment additions/removals match exact set diff.
    public function test_convert_to_admin_selected_assignment_diff_matches_exactly(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessC = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $existing = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->user_id, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $existing->id, 'business_id' => $businessA->id]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $existing->id, 'business_id' => $businessB->id]);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, [$businessB->id, $businessC->id]),
        );

        $persistedIds = WorkspaceMembershipBusiness::where('workspace_membership_id', $existing->id)->pluck('business_id')->sort()->values()->all();
        $this->assertSame(collect([$businessB->id, $businessC->id])->sort()->values()->all(), $persistedIds);
        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, 1);
        Event::assertDispatched(WorkspaceMembershipBusinessAssigned::class, fn (WorkspaceMembershipBusinessAssigned $e) => $e->businessId === $businessC->id);
        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, 1);
        Event::assertDispatched(WorkspaceMembershipBusinessUnassigned::class, fn (WorkspaceMembershipBusinessUnassigned $e) => $e->businessId === $businessA->id);
    }

    // 38. All scope clears stale assignments.
    public function test_convert_to_admin_all_scope_clears_stale_assignments(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $existing = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->user_id, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $existing->id, 'business_id' => $business->id]);

        $this->manager()->transferOwnership(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $existing->id)->count());
    }

    // 39. Event order matches the approved deterministic order.
    public function test_full_event_order_for_existing_previous_owner_reconciliation(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => true]);

        $previousMembership = $this->createAnomalousPreviousOwnerMembership($workspace, $owner->user_id, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => false,
        ]);
        // Anomalous stray grant under All scope, to exercise both an
        // addition and a removal in the same reconciliation below.
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $previousMembership->id, 'business_id' => $businessA->id]);

        $order = [];
        Event::listen(WorkspaceMembershipDeactivated::class, function (WorkspaceMembershipDeactivated $e) use (&$order) {
            $order[] = "deactivated:{$e->membershipId}";
        });
        Event::listen(WorkspaceMembershipReactivated::class, function () use (&$order) {
            $order[] = 'reactivated';
        });
        Event::listen(WorkspaceMembershipRoleChanged::class, function () use (&$order) {
            $order[] = 'role_changed';
        });
        Event::listen(WorkspaceMembershipBusinessAccessScopeChanged::class, function () use (&$order) {
            $order[] = 'scope_changed';
        });
        Event::listen(WorkspaceMembershipBusinessAssigned::class, function (WorkspaceMembershipBusinessAssigned $e) use (&$order) {
            $order[] = "assigned:{$e->businessId}";
        });
        Event::listen(WorkspaceMembershipBusinessUnassigned::class, function (WorkspaceMembershipBusinessUnassigned $e) use (&$order) {
            $order[] = "unassigned:{$e->businessId}";
        });
        Event::listen(WorkspaceOwnershipTransferred::class, function () use (&$order) {
            $order[] = 'ownership_transferred';
        });

        $this->manager()->transferOwnership(
            $owner->user_id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, [$businessB->id]),
        );

        $this->assertSame([
            "deactivated:{$incomingMembership->id}",
            'reactivated',
            'role_changed',
            'scope_changed',
            "assigned:{$businessB->id}",
            "unassigned:{$businessA->id}",
            'ownership_transferred',
        ], $order);
    }

    // 40. Existing anomalous active owner-membership row is reconciled in
    // place rather than rejected.
    public function test_existing_anomalous_active_owner_membership_is_reconciled_not_rejected(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createAnomalousPreviousOwnerMembership($workspace, $owner->id, ['is_active' => true]);

        $result = $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        $this->assertSame($newOwner->id, $result->owner_user_id);
        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->first();
        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->role);
    }

    // --- ROLLBACK AND VALIDATION ---

    // 41. Cross-Workspace Business ID rolls back everything.
    public function test_cross_workspace_business_id_in_disposition_rolls_back_everything(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => true]);

        Event::fake(self::ALL_EVENTS);

        try {
            $this->manager()->transferOwnership(
                $owner->user_id, $workspace, $newOwner->id,
                WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, [$foreignBusiness->id]),
            );
            $this->fail('Expected CrossWorkspaceAssignmentException was not thrown.');
        } catch (CrossWorkspaceAssignmentException) {
            // expected
        }

        $this->assertSame($owner->user_id, $workspace->fresh()->owner_user_id);
        $this->assertTrue($incomingMembership->fresh()->is_active);
        $this->assertSame(0, WorkspaceTransition::count());
        Event::assertNotDispatched(WorkspaceMembershipDeactivated::class);
        Event::assertNotDispatched(WorkspaceOwnershipTransferred::class);
    }

    // 42. Mixed valid/invalid IDs produce no partial changes.
    public function test_mixed_valid_and_invalid_ids_in_disposition_produce_no_partial_changes(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $validBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $foreignBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);

        try {
            $this->manager()->transferOwnership(
                $owner->user_id, $workspace, $newOwner->id,
                WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected, [$validBusiness->id, $foreignBusiness->id]),
            );
        } catch (CrossWorkspaceAssignmentException) {
            // expected
        }

        $this->assertNull(WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->user_id)->first());
    }

    // 43 & 45. Repository/update failure rolls back everything, including
    // writing no transition.
    public function test_repository_failure_rolls_back_everything(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $incomingMembership = $this->createMembership($workspace, $newOwner, ['is_active' => true]);

        $failingTransitionRepository = Mockery::mock(WorkspaceTransitionRepository::class);
        $failingTransitionRepository->shouldReceive('create')
            ->once()
            ->andThrow(new RuntimeException('Simulated transition-write failure.'));
        $this->app->instance(WorkspaceTransitionRepository::class, $failingTransitionRepository);

        Event::fake(self::ALL_EVENTS);

        try {
            $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());
            $this->fail('Expected the simulated transition-write failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated transition-write failure.', $e->getMessage());
        }

        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
        $this->assertTrue($incomingMembership->fresh()->is_active);
        $this->assertSame(0, WorkspaceTransition::count());
        Event::assertNotDispatched(WorkspaceMembershipDeactivated::class);
        Event::assertNotDispatched(WorkspaceOwnershipTransferred::class);
    }

    // 44. No event is dispatched from a failed transaction — see
    // test_cross_workspace_business_id_in_disposition_rolls_back_everything()
    // and test_repository_failure_rolls_back_everything() above, both of
    // which already assert this alongside their other rollback checks.

    // 46. Same-owner no-op writes no transition/event — see
    // test_same_owner_transfer_is_a_no_op() above.

    // --- TRANSITION AND FINAL EVENT ---

    // 47 & 48. Exactly one transition is written for a real transfer, with
    // every field correct.
    public function test_exactly_one_transition_with_correct_fields(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $this->assertSame(1, WorkspaceTransition::count());
        $transition = WorkspaceTransition::first();
        $this->assertSame($workspace->id, $transition->workspace_id);
        $this->assertSame(WorkspaceTransitionType::OwnershipTransferred, $transition->transition_type);
        $this->assertSame($owner->id, $transition->actor_user_id);
        $this->assertSame($owner->id, $transition->from_owner_user_id);
        $this->assertSame($newOwner->id, $transition->to_owner_user_id);
        $this->assertSame('deactivate', $transition->previous_owner_disposition);
        $this->assertNull($transition->business_id);
        $this->assertNull($transition->from_workspace_id);
    }

    // 49. forWorkspace() returns the transfer.
    public function test_for_workspace_returns_the_transfer(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->transferOwnership($owner->id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $repository = app(WorkspaceTransitionRepository::class);

        $this->assertSame(1, $repository->forWorkspace($workspace->id)->count());
    }

    // 50. WorkspaceOwnershipTransferred fires last — see
    // test_full_event_order_for_existing_previous_owner_reconciliation()
    // above, whose recorded order ends with 'ownership_transferred'.

    // 51. Event payload contains the correct IDs and mode.
    public function test_event_payload_contains_correct_ids_and_mode(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        Event::fake(self::ALL_EVENTS);

        $this->manager()->transferOwnership(
            $owner->id, $workspace, $newOwner->id,
            WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
        );

        Event::assertDispatched(WorkspaceOwnershipTransferred::class, function (WorkspaceOwnershipTransferred $event) use ($workspace, $owner, $newOwner) {
            return $event->workspaceId === $workspace->id
                && $event->actorUserId === $owner->id
                && $event->previousOwnerUserId === $owner->id
                && $event->newOwnerUserId === $newOwner->id
                && $event->previousOwnerDisposition === 'convert_to_admin';
        });
    }

    // --- PRESERVATION ---

    // 52-56. owner_user_id changes to the incoming owner; name/is_active,
    // Business data, and unrelated memberships/assignments are untouched.
    public function test_preservation_invariants_hold(): void
    {
        $owner = $this->createCustomer();
        $newOwner = $this->createCustomer()->user;
        $unrelatedMember = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user, ['name' => 'Keep This Name', 'is_active' => true]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $originalBusinessName = $business->name;

        $unrelatedWorkspace = $this->createWorkspace($owner->user);
        $unrelatedBusiness = $this->createBusinessForCustomer($owner->user_id, $unrelatedWorkspace->id);
        $unrelatedMembership = $this->manager()->addMember(
            $owner->user_id, $unrelatedWorkspace, $unrelatedMember->id,
            WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$unrelatedBusiness->id],
        );

        $this->manager()->transferOwnership($owner->user_id, $workspace, $newOwner->id, WorkspaceOwnershipTransferDisposition::deactivate());

        $freshWorkspace = $workspace->fresh();
        $this->assertSame($newOwner->id, $freshWorkspace->owner_user_id);
        $this->assertSame('Keep This Name', $freshWorkspace->name);
        $this->assertTrue($freshWorkspace->is_active);

        $freshBusiness = $business->fresh();
        $this->assertSame($owner->user_id, $freshBusiness->customer_id);
        $this->assertSame($workspace->id, $freshBusiness->workspace_id);
        $this->assertSame($originalBusinessName, $freshBusiness->name);

        $this->assertTrue($unrelatedMembership->fresh()->is_active);
        $this->assertSame(
            1,
            WorkspaceMembershipBusiness::where('workspace_membership_id', $unrelatedMembership->id)->where('business_id', $unrelatedBusiness->id)->count()
        );
    }

    // 57. Previous owner becomes an ordinary membership only after
    // ownership changes.
    public function test_previous_owner_membership_created_only_after_ownership_column_changes(): void
    {
        $owner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $queries = $this->captureQueries(function () use ($owner, $workspace, $newOwner) {
            $this->manager()->transferOwnership(
                $owner->id, $workspace, $newOwner->id,
                WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All),
            );
        });

        $ownerUpdateIndex = null;
        $membershipInsertIndex = null;

        foreach ($queries as $index => $query) {
            $sql = strtolower(trim($query['sql']));

            if ($ownerUpdateIndex === null && str_starts_with($sql, 'update') && str_contains($query['sql'], '`workspaces`')) {
                $ownerUpdateIndex = $index;
            }

            if ($membershipInsertIndex === null && str_starts_with($sql, 'insert into') && str_contains($query['sql'], '`workspace_memberships`')) {
                $membershipInsertIndex = $index;
            }
        }

        $this->assertNotNull($ownerUpdateIndex);
        $this->assertNotNull($membershipInsertIndex);
        $this->assertLessThan($membershipInsertIndex, $ownerUpdateIndex);
    }

    // --- BOUNDARY ---

    // 58. No HTTP/controller/request/route/policy work is introduced.
    public function test_no_http_controller_request_route_or_policy_work_introduced(): void
    {
        $this->assertFalse(class_exists('App\Http\Controllers\Customer\WorkspaceOwnershipController'));
        $this->assertFalse(class_exists('App\Http\Requests\Workspace\TransferOwnershipRequest'));
        $this->assertFalse(class_exists('App\Policies\WorkspacePolicy'));
    }

    // 59. No new migration or model change is introduced.
    public function test_no_new_migration_or_model_change_introduced(): void
    {
        $this->assertSame(0, count(glob(database_path('migrations/*transfer_ownership*')) ?: []));
        $this->assertSame(0, count(glob(database_path('migrations/*ownership_transfer*')) ?: []));
    }

    // 60. No later Milestone method is added — Milestone 2's full public
    // surface is now complete.
    public function test_no_later_milestone_method_is_added(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(WorkspaceManager::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );

        $this->assertEqualsCanonicalizing([
            '__construct',
            'resolveLegacyOnboardingWorkspace',
            'userCanAccessBusiness',
            'assertUserCanAccessBusiness',
            'createWorkspace',
            'renameWorkspace',
            'deactivateWorkspace',
            'reactivateWorkspace',
            'addMember',
            'changeMemberRole',
            'deactivateMember',
            'reactivateMember',
            'changeMemberBusinessAccessScope',
            'assignBusinessToMember',
            'unassignBusinessFromMember',
            'createBusinessInWorkspace',
            'reassignBusiness',
            'transferOwnership',
        ], $methods);
    }
}
