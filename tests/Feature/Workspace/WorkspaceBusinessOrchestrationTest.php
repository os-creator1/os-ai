<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Workspace\WorkspaceTransitionType;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Events\Workspace\BusinessReassignedToWorkspace;
use App\Events\Workspace\WorkspaceMembershipBusinessUnassigned;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspaceTransition;
use App\Repositories\Contracts\WorkspaceTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2F: WorkspaceManager::createBusinessInWorkspace(),
 * reassignBusiness().
 */
class WorkspaceBusinessOrchestrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const ALL_EVENTS = [
        BusinessAssignedToWorkspace::class,
        BusinessReassignedToWorkspace::class,
        WorkspaceMembershipBusinessUnassigned::class,
    ];

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    /**
     * M2 fixture-compatibility helper (§14.3, read-only audit finding) —
     * NOT a change to the shared CreatesWorkspaceTestData trait, which
     * stays unassigned-by-default for every other consumer. Wraps
     * createWorkspace() with an explicit valid complimentary Core
     * workspace_plan_assignments row, established via
     * EntitlementManager::assignFirstPlan() with a distinct platform-admin
     * fixture actor, for every pre-existing method whose fixture setup or
     * assertion path relies on a successful createBusinessInWorkspace()/
     * reassignBusiness() call. New tests below that intentionally exercise
     * an unassigned/full-target capacity denial call the shared
     * CreatesWorkspaceTestData::createWorkspace() trait method directly
     * instead, to keep their fixture Workspace genuinely unassigned.
     */
    private function entitledWorkspace($owner, array $overrides = []): Workspace
    {
        $workspace = $this->createWorkspace($owner, $overrides);

        $admin = \App\Models\User::create([
            'first_name' => 'M2Fixture', 'last_name' => 'Admin', 'email' => 'm2fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(\App\Library\Entitlement\EntitlementManager::class)->assignFirstPlan(
            $workspace,
            \App\Enums\Entitlement\WorkspacePlanTier::Core,
            $admin->id,
            'M2 fixture-compatibility assignment.',
            true,
            2,
        );

        return $workspace->fresh();
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

    // --- CREATE BUSINESS ---

    // 1. Owner can create.
    public function test_owner_can_create_business(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());

        $this->assertSame($workspace->id, $business->workspace_id);
        $this->assertSame($owner->user_id, $business->customer_id);
    }

    // 2. Active Admin can create.
    public function test_active_admin_can_create_business(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $this->createMembership($workspace, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $business = $this->manager()->createBusinessInWorkspace($admin->user_id, $owner, $workspace, $this->businessAttributes());

        $this->assertSame($workspace->id, $business->workspace_id);
    }

    // 3-5. Staff, inactive Admin, and unrelated User cannot create.
    public function test_staff_inactive_admin_and_unrelated_user_cannot_create_business(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $staff = $this->createCustomer();
        $this->createMembership($workspace, $staff->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $inactiveAdmin = $this->createCustomer();
        $this->createMembership($workspace, $inactiveAdmin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $unrelated = $this->createCustomer();

        foreach ([$staff->user_id, $inactiveAdmin->user_id, $unrelated->user_id] as $actorId) {
            try {
                $this->manager()->createBusinessInWorkspace($actorId, $owner, $workspace, $this->businessAttributes());
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 6. Inactive Workspace rejects creation.
    public function test_inactive_workspace_rejects_creation(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user, ['is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());
    }

    // 7. Missing Workspace throws WorkspaceNotFoundException.
    public function test_missing_workspace_throws(): void
    {
        $owner = $this->createCustomer();
        $phantom = new Workspace(['name' => 'Phantom', 'owner_user_id' => $owner->user_id, 'is_active' => true]);
        $phantom->id = 999999999;

        $this->expectException(WorkspaceNotFoundException::class);
        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $phantom, $this->businessAttributes());
    }

    // 8. Explicit Customer is preserved.
    public function test_explicit_customer_is_preserved(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());

        $this->assertSame($owner->user_id, $business->customer_id);
    }

    // 9. Business customer may differ from Workspace owner.
    public function test_business_customer_may_differ_from_workspace_owner(): void
    {
        $owner = $this->createCustomer();
        $differentCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $differentCustomer, $workspace, $this->businessAttributes());

        $this->assertSame($differentCustomer->user_id, $business->customer_id);
        $this->assertNotSame($owner->user_id, $business->customer_id);
    }

    // 10. Business customer may differ from actor.
    public function test_business_customer_may_differ_from_actor(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer();
        $differentCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $this->createMembership($workspace, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $business = $this->manager()->createBusinessInWorkspace($admin->user_id, $differentCustomer, $workspace, $this->businessAttributes());

        $this->assertSame($differentCustomer->user_id, $business->customer_id);
        $this->assertNotSame($admin->user_id, $business->customer_id);
    }

    // 11. workspace_id exists on the initial Business insert.
    public function test_workspace_id_exists_on_initial_insert(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $queries = $this->captureQueries(function () use ($owner, $workspace) {
            $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());
        });

        $inserts = array_values(array_filter(
            $queries,
            fn ($q) => str_starts_with(strtolower(trim($q['sql'])), 'insert into') && str_contains($q['sql'], '`businesses`')
        ));
        $updates = array_values(array_filter(
            $queries,
            fn ($q) => str_starts_with(strtolower(trim($q['sql'])), 'update') && str_contains($q['sql'], '`businesses`')
        ));

        $this->assertCount(1, $inserts);
        $this->assertCount(0, $updates);
        $this->assertContains($workspace->id, $inserts[0]['bindings']);
    }

    // 12. Caller-mutated Workspace state is ignored.
    public function test_caller_mutated_workspace_state_is_ignored(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $stale = clone $workspace;
        $stale->is_active = false;
        $stale->owner_user_id = $unrelated->user_id;

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $stale, $this->businessAttributes());

        $this->assertSame($workspace->id, $business->workspace_id);
    }

    // 13. Successful creation dispatches BusinessAssignedToWorkspace once.
    public function test_successful_creation_dispatches_business_assigned_once(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        Event::fake(self::ALL_EVENTS);

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());

        Event::assertDispatched(BusinessAssignedToWorkspace::class, 1);
        Event::assertDispatched(BusinessAssignedToWorkspace::class, function (BusinessAssignedToWorkspace $event) use ($business, $workspace, $owner) {
            return $event->businessId === $business->id
                && $event->workspaceId === $workspace->id
                && $event->actorUserId === $owner->user_id;
        });
    }

    // 14. Failed creation rolls back Business and event.
    public function test_failed_creation_rolls_back_business_and_event(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $bogusCustomer = new Customer(['user_id' => 999999999]);

        Event::fake(self::ALL_EVENTS);

        try {
            $this->manager()->createBusinessInWorkspace($owner->user_id, $bogusCustomer, $workspace, $this->businessAttributes());
            $this->fail('Expected a database exception for a non-existent customer_id.');
        } catch (\Throwable) {
            // expected — customer_id foreign-key violation
        }

        Event::assertNotDispatched(BusinessAssignedToWorkspace::class);
        $this->assertSame(0, Business::where('workspace_id', $workspace->id)->count());
    }

    // 15. No workspace_transitions row is created.
    public function test_create_business_writes_no_transition_row(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);

        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());

        $this->assertSame(0, WorkspaceTransition::count());
    }

    // --- REASSIGNMENT ---

    // 16. Workspace owner with authority over both may reassign.
    public function test_owner_of_both_workspaces_may_reassign(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $result = $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $this->assertSame($workspaceB->id, $result->workspace_id);
    }

    // 17. Active Admin in both may reassign.
    public function test_active_admin_of_both_workspaces_may_reassign(): void
    {
        $ownerA = $this->createCustomer();
        $ownerB = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $this->createMembership($workspaceA, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $this->createMembership($workspaceB, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $business = $this->manager()->createBusinessInWorkspace($ownerA->user_id, $ownerA, $workspaceA, $this->businessAttributes());

        $result = $this->manager()->reassignBusiness($admin->user_id, $business, $workspaceB);

        $this->assertSame($workspaceB->id, $result->workspace_id);
    }

    // 18. Authority over source only is rejected.
    public function test_authority_over_source_only_is_rejected(): void
    {
        $ownerA = $this->createCustomer();
        $ownerB = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $business = $this->manager()->createBusinessInWorkspace($ownerA->user_id, $ownerA, $workspaceA, $this->businessAttributes());

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reassignBusiness($ownerA->user_id, $business, $workspaceB);
    }

    // 19. Authority over target only is rejected.
    public function test_authority_over_target_only_is_rejected(): void
    {
        $ownerA = $this->createCustomer();
        $ownerB = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $business = $this->manager()->createBusinessInWorkspace($ownerA->user_id, $ownerA, $workspaceA, $this->businessAttributes());

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reassignBusiness($ownerB->user_id, $business, $workspaceB);
    }

    // 20. Staff/inactive Admin/unrelated User is rejected.
    public function test_staff_inactive_admin_and_unrelated_user_cannot_reassign(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);

        $staff = $this->createCustomer();
        $this->createMembership($workspaceA, $staff->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $inactiveAdmin = $this->createCustomer();
        $this->createMembership($workspaceA, $inactiveAdmin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        $unrelated = $this->createCustomer();

        foreach ([$staff->user_id, $inactiveAdmin->user_id, $unrelated->user_id] as $actorId) {
            $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

            try {
                $this->manager()->reassignBusiness($actorId, $business, $workspaceB);
                $this->fail("Expected UnauthorizedWorkspaceManagementException for actor [{$actorId}].");
            } catch (UnauthorizedWorkspaceManagementException $e) {
                $this->assertSame($actorId, $e->actorUserId);
            }
        }
    }

    // 21. Inactive source is rejected.
    public function test_inactive_source_workspace_is_rejected(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $this->manager()->deactivateWorkspace($owner->user_id, $workspaceA);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);
    }

    // 22. Inactive target is rejected.
    public function test_inactive_target_workspace_is_rejected(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $this->manager()->deactivateWorkspace($owner->user_id, $workspaceB);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);
    }

    // 23. Missing source or target Workspace throws.
    public function test_missing_source_or_target_workspace_throws(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $phantomTarget = new Workspace(['name' => 'Phantom', 'owner_user_id' => $owner->user_id, 'is_active' => true]);
        $phantomTarget->id = 999999999;

        try {
            $this->manager()->reassignBusiness($owner->user_id, $business, $phantomTarget);
            $this->fail('Expected WorkspaceNotFoundException for a missing target Workspace.');
        } catch (WorkspaceNotFoundException $e) {
            $this->assertSame(999999999, $e->workspaceId);
        }

        $mutatedSource = clone $business;
        $mutatedSource->workspace_id = 999999998;

        try {
            $this->manager()->reassignBusiness($owner->user_id, $mutatedSource, $workspaceA);
            $this->fail('Expected WorkspaceNotFoundException for a missing source Workspace.');
        } catch (WorkspaceNotFoundException $e) {
            $this->assertSame(999999998, $e->workspaceId);
        }
    }

    // 24. Missing Business throws WorkspaceBusinessNotFoundException.
    public function test_missing_business_throws(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);

        $phantomBusiness = new Business($this->businessAttributes());
        $phantomBusiness->customer_id = $owner->user_id;
        $phantomBusiness->workspace_id = $workspaceA->id;
        $phantomBusiness->id = 999999997;

        $this->expectException(WorkspaceBusinessNotFoundException::class);
        $this->manager()->reassignBusiness($owner->user_id, $phantomBusiness, $workspaceB);
    }

    // 25. Caller-stale Business source throws BusinessWorkspaceMismatchException.
    public function test_caller_stale_business_source_throws_mismatch(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $workspaceC = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $stale = clone $business;
        $stale->workspace_id = $workspaceC->id;

        try {
            $this->manager()->reassignBusiness($owner->user_id, $stale, $workspaceB);
            $this->fail('Expected BusinessWorkspaceMismatchException was not thrown.');
        } catch (BusinessWorkspaceMismatchException $e) {
            $this->assertSame($business->id, $e->businessId);
            $this->assertSame($workspaceC->id, $e->expectedWorkspaceId);
            $this->assertSame($workspaceA->id, $e->actualWorkspaceId);
        }
    }

    // 26. Caller-mutated target Workspace state is ignored.
    public function test_caller_mutated_target_workspace_state_is_ignored(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $staleTarget = clone $workspaceB;
        $staleTarget->is_active = false;
        $staleTarget->owner_user_id = $unrelated->user_id;

        $result = $this->manager()->reassignBusiness($owner->user_id, $business, $staleTarget);

        $this->assertSame($workspaceB->id, $result->workspace_id);
    }

    // 27. Same-target call is an authorized no-op.
    public function test_same_target_call_is_a_no_op(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        Event::fake(self::ALL_EVENTS);

        $result = $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceA);

        $this->assertSame($workspaceA->id, $result->workspace_id);
        Event::assertNotDispatched(BusinessReassignedToWorkspace::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
        $this->assertSame(0, WorkspaceTransition::count());
    }

    // 28. Unauthorized same-target call still throws.
    public function test_unauthorized_same_target_call_still_throws(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reassignBusiness($unrelated->user_id, $business, $workspaceA);
    }

    // 29-31. workspace_id changes to target; customer_id and unrelated fields unchanged.
    public function test_reassignment_changes_only_workspace_id(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $originalName = $business->name;
        $originalCustomerId = $business->customer_id;

        $result = $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $this->assertSame($workspaceB->id, $result->workspace_id);
        $this->assertSame($originalCustomerId, $result->customer_id);
        $this->assertSame($originalName, $result->fresh()->name);
    }

    // 32-34. All source-Workspace grants for the Business are removed; another
    // Business's grants (same source Workspace) remain; grants outside the
    // verified source Workspace remain untouched.
    public function test_only_the_reassigned_businesss_source_workspace_grants_are_removed(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $workspaceC = $this->entitledWorkspace($owner->user);

        $member1 = $this->createCustomer()->user;
        $member2 = $this->createCustomer()->user;
        $member3 = $this->createCustomer()->user;

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $otherBusinessInA = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes(['name' => 'Other In A']));
        $businessInC = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceC, $this->businessAttributes(['name' => 'In C']));

        $membershipInA1 = $this->manager()->addMember($owner->user_id, $workspaceA, $member1->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id]);
        $membershipInA2 = $this->manager()->addMember($owner->user_id, $workspaceA, $member2->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$otherBusinessInA->id]);
        $membershipInC = $this->manager()->addMember($owner->user_id, $workspaceC, $member3->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$businessInC->id]);

        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membershipInA1->id)->count());
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membershipInA2->id)->count());
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membershipInC->id)->count());
    }

    // 35-37. One Unassigned event fires per removed grant, deterministically
    // ordered, then BusinessReassignedToWorkspace fires last.
    public function test_unassigned_events_fire_deterministically_then_reassigned_fires_last(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $memberX = $this->createCustomer()->user;
        $memberY = $this->createCustomer()->user;

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        // Created out of ascending-membership-ID order, to prove the
        // dispatch order is sorted rather than insertion order.
        $membershipY = $this->manager()->addMember($owner->user_id, $workspaceA, $memberY->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id]);
        $membershipX = $this->manager()->addMember($owner->user_id, $workspaceA, $memberX->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id]);

        $expectedOrder = collect([$membershipY->id, $membershipX->id])->sort()->values()->all();

        $order = [];
        Event::listen(WorkspaceMembershipBusinessUnassigned::class, function (WorkspaceMembershipBusinessUnassigned $event) use (&$order) {
            $order[] = "unassigned:{$event->membershipId}";
        });
        Event::listen(BusinessReassignedToWorkspace::class, function () use (&$order) {
            $order[] = 'reassigned';
        });

        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $this->assertSame([
            "unassigned:{$expectedOrder[0]}",
            "unassigned:{$expectedOrder[1]}",
            'reassigned',
        ], $order);
    }

    // 38. No Unassigned event fires when no grants exist.
    public function test_no_unassigned_event_fires_when_no_grants_exist(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        Event::fake(self::ALL_EVENTS);

        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
        Event::assertDispatched(BusinessReassignedToWorkspace::class, 1);
    }

    // 39-40. Exactly one durable transition is created, with fields matching
    // source, target, actor and Business.
    public function test_exactly_one_transition_is_created_with_correct_fields(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $this->assertSame(1, WorkspaceTransition::count());
        $transition = WorkspaceTransition::first();
        $this->assertSame($workspaceB->id, $transition->workspace_id);
        $this->assertSame(WorkspaceTransitionType::BusinessReassigned, $transition->transition_type);
        $this->assertSame($owner->user_id, $transition->actor_user_id);
        $this->assertSame($business->id, $transition->business_id);
        $this->assertSame($workspaceA->id, $transition->from_workspace_id);
        $this->assertNull($transition->from_owner_user_id);
        $this->assertNull($transition->to_owner_user_id);
        $this->assertNull($transition->previous_owner_disposition);
    }

    // 41. forWorkspace() exposes the transition in both source and target histories.
    public function test_for_workspace_exposes_transition_in_both_histories(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $repository = app(WorkspaceTransitionRepository::class);

        $this->assertSame(1, $repository->forWorkspace($workspaceA->id)->count());
        $this->assertSame(1, $repository->forWorkspace($workspaceB->id)->count());
    }

    // 42. Same-target no-op writes no transition or event — see
    // test_same_target_call_is_a_no_op() above, which already asserts
    // WorkspaceTransition::count() === 0 alongside the no-event assertions.

    // 43. Failure rolls back cleanup, Business update, transition and events.
    public function test_failure_rolls_back_cleanup_business_update_and_transition(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $member = $this->createCustomer()->user;
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $membership = $this->manager()->addMember($owner->user_id, $workspaceA, $member->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$business->id]);

        $failingTransitionRepository = \Mockery::mock(WorkspaceTransitionRepository::class);
        $failingTransitionRepository->shouldReceive('create')
            ->once()
            ->andThrow(new RuntimeException('Simulated transition-write failure.'));
        $this->app->instance(WorkspaceTransitionRepository::class, $failingTransitionRepository);

        Event::fake(self::ALL_EVENTS);

        try {
            $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);
            $this->fail('Expected the simulated transition-write failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated transition-write failure.', $e->getMessage());
        }

        $this->assertSame($workspaceA->id, $business->fresh()->workspace_id);
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
        Event::assertNotDispatched(BusinessReassignedToWorkspace::class);
    }

    // 44. Authoritative Business row is used instead of caller-mutated workspace_id/customer_id.
    public function test_authoritative_business_row_is_used_over_caller_mutated_fields(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $mutated = clone $business;
        $mutated->customer_id = $otherCustomer->user_id;

        $result = $this->manager()->reassignBusiness($owner->user_id, $mutated, $workspaceB);

        $this->assertSame($owner->user_id, $result->customer_id);
        $this->assertNotSame($otherCustomer->user_id, $result->customer_id);
    }

    // --- LOCKING ---

    // 45-46. Source and target Workspaces are locked in ascending ID order;
    // the Business is locked after the Workspace locks.
    public function test_workspaces_locked_ascending_then_business_locked_last(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $this->assertLessThan($workspaceB->id, $workspaceA->id);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $queries = $this->captureQueries(function () use ($owner, $business, $workspaceB) {
            $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);
        });

        $lockQueries = array_values(array_filter($queries, fn ($q) => str_contains(strtolower($q['sql']), 'for update')));

        $this->assertGreaterThanOrEqual(3, count($lockQueries));
        $this->assertStringContainsString('`workspaces`', $lockQueries[0]['sql']);
        $this->assertSame([$workspaceA->id], $lockQueries[0]['bindings']);
        $this->assertStringContainsString('`workspaces`', $lockQueries[1]['sql']);
        $this->assertSame([$workspaceB->id], $lockQueries[1]['bindings']);
        $this->assertStringContainsString('`businesses`', $lockQueries[2]['sql']);
        $this->assertSame([$business->id], $lockQueries[2]['bindings']);
    }

    // 47. Opposite-direction reassignment logic cannot acquire Workspace
    // locks in reverse order.
    public function test_opposite_direction_reassignments_lock_workspaces_in_the_same_ascending_order(): void
    {
        $owner = $this->createCustomer();
        $workspaceLow = $this->entitledWorkspace($owner->user);
        $workspaceHigh = $this->entitledWorkspace($owner->user);
        $this->assertLessThan($workspaceHigh->id, $workspaceLow->id);

        $businessInLow = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceLow, $this->businessAttributes(['name' => 'In Low']));
        $businessInHigh = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceHigh, $this->businessAttributes(['name' => 'In High']));

        $queriesLowToHigh = $this->captureQueries(function () use ($owner, $businessInLow, $workspaceHigh) {
            $this->manager()->reassignBusiness($owner->user_id, $businessInLow, $workspaceHigh);
        });

        $queriesHighToLow = $this->captureQueries(function () use ($owner, $businessInHigh, $workspaceLow) {
            $this->manager()->reassignBusiness($owner->user_id, $businessInHigh, $workspaceLow);
        });

        foreach ([$queriesLowToHigh, $queriesHighToLow] as $queries) {
            $workspaceLockQueries = array_values(array_filter(
                $queries,
                fn ($q) => str_contains(strtolower($q['sql']), 'for update') && str_contains($q['sql'], '`workspaces`')
            ));

            $this->assertCount(2, $workspaceLockQueries);
            $this->assertSame([$workspaceLow->id], $workspaceLockQueries[0]['bindings']);
            $this->assertSame([$workspaceHigh->id], $workspaceLockQueries[1]['bindings']);
        }
    }

    // --- BOUNDARY ---

    // 48. transferOwnership() is now implemented (Slice 2G) — this file's
    // former forward-looking "not yet added" assertion is retired; see
    // WorkspaceOwnershipTransferTest for its own coverage.

    // 49. reassignBusiness() creates no ownership-transfer event or
    // transition of its own — it writes only a business_reassigned_to_
    // workspace transition with null owner fields, never an ownership-
    // transfer one, even though WorkspaceOwnershipTransferred now exists
    // (Slice 2G) as a class WorkspaceManager can dispatch elsewhere.
    public function test_reassignment_creates_no_ownership_transfer_event_or_transition(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $workspaceB = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());

        $this->manager()->reassignBusiness($owner->user_id, $business, $workspaceB);

        $transition = WorkspaceTransition::first();
        $this->assertNotSame(WorkspaceTransitionType::OwnershipTransferred, $transition->transition_type);
        $this->assertNull($transition->from_owner_user_id);
        $this->assertNull($transition->to_owner_user_id);
    }

    // --- SLICE 4E: SELECTED-SCOPE BUSINESS-ACCESS CORRECTION ---

    // 51. All-scope active Admin remains allowed (regression confirmation
    // for the new Business-access check inserted after the existing
    // authority/active-state checks, before the same-target no-op).
    public function test_all_scope_active_admin_reassignment_remains_allowed(): void
    {
        $ownerA = $this->createCustomer();
        $ownerB = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $this->createMembership($workspaceA, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $this->createMembership($workspaceB, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);
        $business = $this->manager()->createBusinessInWorkspace($ownerA->user_id, $ownerA, $workspaceA, $this->businessAttributes());

        $result = $this->manager()->reassignBusiness($admin->user_id, $business, $workspaceB);

        $this->assertSame($workspaceB->id, $result->workspace_id);
    }

    // 52. Selected-scope Admin may reassign a Business explicitly assigned
    // to their own membership, when also authorized over the target.
    public function test_selected_scope_admin_may_reassign_an_assigned_business(): void
    {
        $ownerA = $this->createCustomer();
        $ownerB = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $business = $this->manager()->createBusinessInWorkspace($ownerA->user_id, $ownerA, $workspaceA, $this->businessAttributes());
        $this->manager()->addMember($ownerA->user_id, $workspaceA, $admin->user_id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected, [$business->id]);
        $this->createMembership($workspaceB, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $result = $this->manager()->reassignBusiness($admin->user_id, $business, $workspaceB);

        $this->assertSame($workspaceB->id, $result->workspace_id);
    }

    // 53. Selected-scope Admin cannot reassign a Business not assigned to
    // their own membership, even with management authority over both
    // Workspaces -- the previously-unexercised gap this slice closes.
    public function test_selected_scope_admin_cannot_reassign_an_unassigned_business(): void
    {
        $ownerA = $this->createCustomer();
        $ownerB = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $business = $this->manager()->createBusinessInWorkspace($ownerA->user_id, $ownerA, $workspaceA, $this->businessAttributes());
        $this->manager()->addMember($ownerA->user_id, $workspaceA, $admin->user_id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected, []);
        $this->createMembership($workspaceB, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $this->expectException(WorkspaceAccessDeniedException::class);
        $this->manager()->reassignBusiness($admin->user_id, $business, $workspaceB);
    }

    // 54. Direct Business ownership (Business.customer_id) is an
    // independent access route but does not substitute for Workspace
    // management authority over source and target.
    public function test_direct_business_owner_without_management_authority_cannot_reassign(): void
    {
        $workspaceOwner = $this->createCustomer();
        $businessOwner = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($workspaceOwner->user);
        $workspaceB = $this->entitledWorkspace($workspaceOwner->user);
        $business = $this->manager()->createBusinessInWorkspace($workspaceOwner->user_id, $businessOwner, $workspaceA, $this->businessAttributes());

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reassignBusiness($businessOwner->user_id, $business, $workspaceB);
    }

    // 55. Same-target call by a selected-scope Admin whose membership is
    // assigned the Business is still an authorized no-op.
    public function test_same_target_no_op_for_selected_scope_admin_with_assigned_business(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $this->manager()->addMember($owner->user_id, $workspaceA, $admin->user_id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected, [$business->id]);

        $result = $this->manager()->reassignBusiness($admin->user_id, $business, $workspaceA);

        $this->assertSame($workspaceA->id, $result->workspace_id);
    }

    // 56. Same-target call by a selected-scope Admin whose membership is
    // NOT assigned the Business still fails on Business access -- the
    // no-op is reached only after every required check, including access,
    // has passed.
    public function test_same_target_call_denied_for_selected_scope_admin_with_unassigned_business(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer();
        $workspaceA = $this->entitledWorkspace($owner->user);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspaceA, $this->businessAttributes());
        $this->manager()->addMember($owner->user_id, $workspaceA, $admin->user_id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected, []);

        $this->expectException(WorkspaceAccessDeniedException::class);
        $this->manager()->reassignBusiness($admin->user_id, $business, $workspaceA);
    }

    // 50. No HTTP/model/migration scope is introduced.
    public function test_no_http_model_or_migration_scope_introduced(): void
    {
        $this->assertFalse(class_exists('App\Http\Controllers\Customer\WorkspaceBusinessController'));
        $this->assertSame(0, count(glob(database_path('migrations/*reassign*')) ?: []));
        $this->assertSame(0, count(glob(database_path('migrations/*transfer_ownership*')) ?: []));
    }

    // --- M2 CAPACITY ENFORCEMENT (§13.A/§13.B, read-only audit finding) ---

    private function fillToCapacity(Workspace $workspace, Customer $owner, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes(['name' => "Fixture {$i}-" . uniqid()]));
        }
    }

    public function test_final_slot_available_succeeds(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $this->fillToCapacity($workspace, $owner, 4);

        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes(['name' => 'Final']));

        $this->assertSame($workspace->id, $business->workspace_id);
        $this->assertSame(5, Business::where('workspace_id', $workspace->id)->count());
    }

    public function test_full_target_denies_with_business_slot_limit_exceeded(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $this->fillToCapacity($workspace, $owner, 5);

        $this->expectException(\App\Exceptions\Entitlement\BusinessSlotLimitExceededException::class);
        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes(['name' => 'Sixth']));
    }

    public function test_unassigned_target_denies_with_workspace_plan_unassigned(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);

        $this->expectException(\App\Exceptions\Entitlement\WorkspacePlanUnassignedException::class);
        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes());
    }

    public function test_inactive_plan_target_denies_with_inactive_workspace_plan(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $admin = \App\Models\User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus(
            $workspace->fresh(),
            \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Inactive,
            $admin->id,
            'Fixture.',
        );

        $this->expectException(\App\Exceptions\Entitlement\InactiveWorkspacePlanException::class);
        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace->fresh(), $this->businessAttributes());
    }

    public function test_suspended_plan_target_denies_with_suspended_workspace_plan(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $admin = \App\Models\User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus(
            $workspace->fresh(),
            \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Suspended,
            $admin->id,
            'Fixture.',
        );

        $this->expectException(\App\Exceptions\Entitlement\SuspendedWorkspacePlanException::class);
        $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace->fresh(), $this->businessAttributes());
    }

    public function test_source_and_destination_isolation_a_race_on_one_workspace_never_affects_an_unrelated_workspace(): void
    {
        $owner = $this->createCustomer();
        $fullWorkspace = $this->entitledWorkspace($owner->user);
        $this->fillToCapacity($fullWorkspace, $owner, 5);

        $unrelatedOwner = $this->createCustomer();
        $unrelatedWorkspace = $this->entitledWorkspace($unrelatedOwner->user);

        try {
            $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $fullWorkspace, $this->businessAttributes());
        } catch (\App\Exceptions\Entitlement\BusinessSlotLimitExceededException) {
            // expected
        }

        $business = $this->manager()->createBusinessInWorkspace($unrelatedOwner->user_id, $unrelatedOwner, $unrelatedWorkspace, $this->businessAttributes());
        $this->assertSame($unrelatedWorkspace->id, $business->workspace_id);
    }

    public function test_same_target_no_op_remains_a_no_op_even_when_the_workspace_is_at_full_capacity(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $this->fillToCapacity($workspace, $owner, 4);
        $business = $this->manager()->createBusinessInWorkspace($owner->user_id, $owner, $workspace, $this->businessAttributes(['name' => 'Final']));
        // Workspace is now at exact capacity (5/5).

        $result = $this->manager()->reassignBusiness($owner->user_id, $business, $workspace);

        $this->assertSame($workspace->id, $result->workspace_id);
    }
}
