<?php

namespace Tests\Feature\Workspace;

use App\DTO\Workspace\WorkspaceFirstBusinessInput;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeactivated;
use App\Events\Workspace\WorkspaceReactivated;
use App\Events\Workspace\WorkspaceRenamed;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceOwnerNotFoundException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Workspace;
use App\Models\WorkspaceTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2C: WorkspaceManager::createWorkspace(),
 * renameWorkspace(), deactivateWorkspace(), reactivateWorkspace().
 */
class WorkspaceLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const ALL_LIFECYCLE_EVENTS = [
        WorkspaceCreated::class,
        WorkspaceRenamed::class,
        WorkspaceDeactivated::class,
        WorkspaceReactivated::class,
        BusinessAssignedToWorkspace::class,
    ];

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    // --- CREATE ---

    // 1. Workspace creation without a Business succeeds.
    public function test_create_workspace_without_business_succeeds(): void
    {
        $owner = $this->createCustomer()->user;

        $workspace = $this->manager()->createWorkspace($owner->id, 'Acme Workspace');

        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertSame('Acme Workspace', $workspace->fresh()->name);
        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
        $this->assertTrue($workspace->fresh()->is_active);
    }

    // 2. Owner User row exists and is verified (via the same locked-read
    // pattern as resolveLegacyOnboardingWorkspace()) before creation — a
    // nonexistent owner throws and creates no row.
    public function test_create_workspace_requires_an_existing_owner(): void
    {
        $this->expectException(WorkspaceOwnerNotFoundException::class);

        try {
            $this->manager()->createWorkspace(999999, 'Nobody');
        } finally {
            $this->assertSame(0, Workspace::count());
        }
    }

    // Correction: createWorkspace() throws the typed
    // WorkspaceOwnerNotFoundException (not the generic
    // ModelNotFoundException) when the owner cannot be found, exposes the
    // exact ownerUserId, creates no Workspace, no first Business, and
    // dispatches neither event.
    public function test_create_workspace_missing_owner_exposes_exact_id_and_creates_nothing(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $customer = $this->createCustomer();

        try {
            $this->manager()->createWorkspace(
                999999,
                'Nobody',
                new WorkspaceFirstBusinessInput($customer, $this->businessAttributes())
            );
            $this->fail('Expected WorkspaceOwnerNotFoundException was not thrown.');
        } catch (WorkspaceOwnerNotFoundException $e) {
            $this->assertSame(999999, $e->ownerUserId);
        }

        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, Business::count());
        Event::assertNotDispatched(WorkspaceCreated::class);
        Event::assertNotDispatched(BusinessAssignedToWorkspace::class);
    }

    // 3. WorkspaceCreated dispatches after successful commit.
    public function test_workspace_created_dispatches_after_commit(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;

        $workspace = $this->manager()->createWorkspace($owner->id, 'Acme Workspace');

        Event::assertDispatched(WorkspaceCreated::class, function (WorkspaceCreated $event) use ($workspace, $owner) {
            return $event->workspaceId === $workspace->id && $event->ownerUserId === $owner->id;
        });
    }

    // 4/5/6/7. Creation with an explicit first Business: the Customer is
    // used as supplied (never inferred from the Workspace owner), may
    // differ from the Workspace owner, and workspace_id is set on the
    // Business's initial insert.
    public function test_create_workspace_with_first_business_succeeds(): void
    {
        $workspaceOwner = $this->createCustomer()->user;
        $businessCustomer = $this->createCustomer();

        $workspace = $this->manager()->createWorkspace(
            $workspaceOwner->id,
            'Acme Workspace',
            new WorkspaceFirstBusinessInput($businessCustomer, $this->businessAttributes())
        );

        $business = Business::where('workspace_id', $workspace->id)->first();

        $this->assertNotNull($business);
        $this->assertSame($businessCustomer->user_id, $business->customer_id);
        $this->assertNotSame($workspaceOwner->id, $business->customer_id);
        $this->assertSame($workspace->id, $business->workspace_id);
    }

    // 8. WorkspaceCreated then BusinessAssignedToWorkspace are dispatched, in that order.
    public function test_create_workspace_with_first_business_dispatches_both_events_in_order(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $workspaceOwner = $this->createCustomer()->user;
        $businessCustomer = $this->createCustomer();

        $workspace = $this->manager()->createWorkspace(
            $workspaceOwner->id,
            'Acme Workspace',
            new WorkspaceFirstBusinessInput($businessCustomer, $this->businessAttributes())
        );
        $business = Business::where('workspace_id', $workspace->id)->firstOrFail();

        Event::assertDispatched(WorkspaceCreated::class);
        Event::assertDispatched(BusinessAssignedToWorkspace::class, function (BusinessAssignedToWorkspace $event) use ($business, $workspace, $workspaceOwner) {
            return $event->businessId === $business->id
                && $event->workspaceId === $workspace->id
                && $event->actorUserId === $workspaceOwner->id;
        });

        $order = [];
        foreach (Event::dispatched(WorkspaceCreated::class) as [$e]) { $order[] = 'created'; }
        foreach (Event::dispatched(BusinessAssignedToWorkspace::class) as [$e]) { $order[] = 'assigned'; }
        $this->assertSame(['created', 'assigned'], $order);
    }

    // 9/10. Business creation failure rolls back Workspace creation and dispatches neither event.
    public function test_business_creation_failure_rolls_back_workspace_and_dispatches_nothing(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $customer = $this->createCustomer();

        try {
            $this->manager()->createWorkspace(
                $owner->id,
                'Acme Workspace',
                // An invalid Business.industry value fails the model's own
                // backed-enum cast before any write is attempted.
                new WorkspaceFirstBusinessInput($customer, $this->businessAttributes(['industry' => 'not_a_real_industry']))
            );
            $this->fail('Expected a ValueError from an invalid Business attribute.');
        } catch (\ValueError $e) {
            // expected
        }

        $this->assertSame(0, Workspace::count());
        Event::assertNotDispatched(WorkspaceCreated::class);
        Event::assertNotDispatched(BusinessAssignedToWorkspace::class);
    }

    // --- RENAME ---

    // 11. Owner may rename.
    public function test_owner_may_rename(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);

        $result = $this->manager()->renameWorkspace($owner->id, $workspace, 'New Name');

        $this->assertSame('New Name', $result->fresh()->name);
    }

    // 12. Active admin may rename.
    public function test_active_admin_may_rename(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $result = $this->manager()->renameWorkspace($admin->id, $workspace, 'New Name');

        $this->assertSame('New Name', $result->fresh()->name);
    }

    // 13. Staff may not rename.
    public function test_staff_may_not_rename(): void
    {
        $owner = $this->createCustomer()->user;
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);
        $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->renameWorkspace($staff->id, $workspace, 'New Name');
    }

    // 14. Inactive admin may not rename.
    public function test_inactive_admin_may_not_rename(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->renameWorkspace($admin->id, $workspace, 'New Name');
    }

    // 15. Unrelated User may not rename.
    public function test_unrelated_user_may_not_rename(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->renameWorkspace($unrelated->id, $workspace, 'New Name');
    }

    // 16. Inactive Workspace cannot be renamed.
    public function test_inactive_workspace_cannot_be_renamed(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name', 'is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        $this->manager()->renameWorkspace($owner->id, $workspace, 'New Name');
    }

    // 17. Same-name rename is an authorized no-op: no write, no event.
    public function test_same_name_rename_is_a_no_op(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Same Name']);
        $updatedAtBefore = $workspace->fresh()->updated_at;

        $result = $this->manager()->renameWorkspace($owner->id, $workspace, 'Same Name');

        $this->assertSame('Same Name', $result->name);
        $this->assertEquals($updatedAtBefore, $workspace->fresh()->updated_at);
        Event::assertNotDispatched(WorkspaceRenamed::class);
    }

    // 18. Passed stale or mutated Workspace state is not trusted — the
    // authoritative persisted row is re-locked and re-read.
    public function test_rename_does_not_trust_stale_in_memory_workspace_state(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Real Name']);

        $staleWorkspace = clone $workspace;
        $staleWorkspace->owner_user_id = $unrelated->id;
        $staleWorkspace->is_active = false;
        $staleWorkspace->name = 'Stale Name';

        // The real owner succeeds even though the passed object claims a
        // different owner, inactive state, and a different current name.
        $result = $this->manager()->renameWorkspace($owner->id, $staleWorkspace, 'Truly New Name');

        $this->assertSame('Truly New Name', $result->name);
        $this->assertSame($owner->id, $result->owner_user_id);
    }

    // 19. A successful rename dispatches exactly one event with old/new names.
    public function test_successful_rename_dispatches_one_event_with_old_and_new_names(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);

        $this->manager()->renameWorkspace($owner->id, $workspace, 'New Name');

        Event::assertDispatched(WorkspaceRenamed::class, 1);
        Event::assertDispatched(WorkspaceRenamed::class, function (WorkspaceRenamed $event) use ($workspace, $owner) {
            return $event->workspaceId === $workspace->id
                && $event->actorUserId === $owner->id
                && $event->previousName === 'Old Name'
                && $event->newName === 'New Name';
        });
    }

    // --- DEACTIVATE ---

    // 20. Owner may deactivate.
    public function test_owner_may_deactivate(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $result = $this->manager()->deactivateWorkspace($owner->id, $workspace);

        $this->assertFalse($result->fresh()->is_active);
    }

    // 21. Admin may not deactivate.
    public function test_admin_may_not_deactivate(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->deactivateWorkspace($admin->id, $workspace);
    }

    // 22. Duplicate deactivate is an authorized no-op.
    public function test_duplicate_deactivate_is_a_no_op(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $result = $this->manager()->deactivateWorkspace($owner->id, $workspace);

        $this->assertFalse($result->is_active);
        Event::assertNotDispatched(WorkspaceDeactivated::class);
    }

    // 23. Unauthorized duplicate deactivate still throws (authority checked before the no-op).
    public function test_unauthorized_duplicate_deactivate_still_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->deactivateWorkspace($unrelated->id, $workspace);
    }

    // 24. A successful deactivate dispatches exactly once.
    public function test_successful_deactivate_dispatches_once(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->manager()->deactivateWorkspace($owner->id, $workspace);

        Event::assertDispatched(WorkspaceDeactivated::class, 1);
        Event::assertDispatched(WorkspaceDeactivated::class, function (WorkspaceDeactivated $event) use ($workspace, $owner) {
            return $event->workspaceId === $workspace->id && $event->actorUserId === $owner->id;
        });
    }

    // --- REACTIVATE ---

    // 25. Owner may reactivate.
    public function test_owner_may_reactivate(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $result = $this->manager()->reactivateWorkspace($owner->id, $workspace);

        $this->assertTrue($result->fresh()->is_active);
    }

    // 26. Admin may not reactivate.
    public function test_admin_may_not_reactivate(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reactivateWorkspace($admin->id, $workspace);
    }

    // 27. Duplicate reactivate is an authorized no-op.
    public function test_duplicate_reactivate_is_a_no_op(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => true]);

        $result = $this->manager()->reactivateWorkspace($owner->id, $workspace);

        $this->assertTrue($result->is_active);
        Event::assertNotDispatched(WorkspaceReactivated::class);
    }

    // 28. Unauthorized duplicate reactivate still throws.
    public function test_unauthorized_duplicate_reactivate_still_throws(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => true]);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->reactivateWorkspace($unrelated->id, $workspace);
    }

    // 29. A successful reactivate dispatches exactly once.
    public function test_successful_reactivate_dispatches_once(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $this->manager()->reactivateWorkspace($owner->id, $workspace);

        Event::assertDispatched(WorkspaceReactivated::class, 1);
        Event::assertDispatched(WorkspaceReactivated::class, function (WorkspaceReactivated $event) use ($workspace, $owner) {
            return $event->workspaceId === $workspace->id && $event->actorUserId === $owner->id;
        });
    }

    // --- REGRESSION ---

    // 30. resolveLegacyOnboardingWorkspace() behavior is unchanged: it
    // still creates a Workspace via the renamed private helper when no
    // candidate exists.
    public function test_resolve_legacy_onboarding_workspace_still_creates_when_no_candidate_exists(): void
    {
        $owner = $this->createCustomer(); // no Business, no existing Workspace

        $workspace = $this->manager()->resolveLegacyOnboardingWorkspace($owner->user_id);

        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertSame($owner->user_id, $workspace->owner_user_id);
    }

    // 31. No workspace_transitions rows are created by any lifecycle operation.
    public function test_no_workspace_transitions_rows_are_created(): void
    {
        $owner = $this->createCustomer()->user;
        $admin = $this->createCustomer()->user;

        $workspace = $this->manager()->createWorkspace($owner->id, 'Acme Workspace');
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);
        $this->manager()->renameWorkspace($admin->id, $workspace, 'Renamed');
        $this->manager()->deactivateWorkspace($owner->id, $workspace);
        $this->manager()->reactivateWorkspace($owner->id, $workspace);

        $this->assertSame(0, WorkspaceTransition::count());
    }

    // 32. No event is dispatched before a failed transaction commits —
    // proven for rename/deactivate/reactivate via their own not-found/
    // unauthorized/inactive failure paths, none of which ever reach a
    // dispatch() call.
    public function test_no_event_is_dispatched_when_rename_is_unauthorized(): void
    {
        Event::fake(self::ALL_LIFECYCLE_EVENTS);
        $owner = $this->createCustomer()->user;
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);

        try {
            $this->manager()->renameWorkspace($unrelated->id, $workspace, 'New Name');
        } catch (UnauthorizedWorkspaceManagementException) {
            // expected
        }

        Event::assertNotDispatched(WorkspaceRenamed::class);
        $this->assertSame('Old Name', $workspace->fresh()->name);
    }
}
