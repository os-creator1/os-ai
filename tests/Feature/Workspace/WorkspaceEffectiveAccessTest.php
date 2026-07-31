<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\WorkspaceTransition;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2B: WorkspaceManager::userCanAccessBusiness()
 * and assertUserCanAccessBusiness() — the full §14.1 effective-access
 * matrix.
 */
class WorkspaceEffectiveAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    // 1. An inactive Workspace denies access to everyone.
    public function test_inactive_workspace_denies_the_direct_business_owner(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user, ['is_active' => false]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($owner->user_id, $business));
    }

    public function test_inactive_workspace_denies_the_workspace_owner(): void
    {
        $businessOwner = $this->createCustomer();
        $workspaceOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($workspaceOwner, ['is_active' => false]);
        $business = $this->createBusinessForCustomer($businessOwner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($workspaceOwner->id, $business));
    }

    public function test_inactive_workspace_denies_an_active_admin_member(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user, ['is_active' => false]);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($admin->id, $business));
    }

    public function test_inactive_workspace_denies_an_active_staff_member(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user, ['is_active' => false]);
        $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    // 2. A direct Business owner has access while the Workspace is active.
    public function test_direct_business_owner_has_access_while_workspace_active(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertTrue($this->manager()->userCanAccessBusiness($owner->user_id, $business));
    }

    // 3/13. The Workspace owner has access while active, even when
    // Business.customer_id belongs to another Customer/User — the two
    // remain independent.
    public function test_workspace_owner_has_access_even_when_business_belongs_to_another_customer(): void
    {
        $businessOwner = $this->createCustomer();
        $workspaceOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($workspaceOwner);
        $business = $this->createBusinessForCustomer($businessOwner->user_id, $workspace->id);

        $this->assertNotSame($workspaceOwner->id, $business->customer_id);
        $this->assertTrue($this->manager()->userCanAccessBusiness($workspaceOwner->id, $business));
    }

    // 4. An active admin membership has access to every Business in the
    // Workspace. Per §14.1's exact algorithm, `role` is never consulted by
    // userCanAccessBusiness() at all — only business_access_scope is
    // (§7.5: "role never implies scope"; role governs management
    // authority, a separate axis not evaluated by this read-only method).
    // An admin's access to every Business therefore depends on scope=all,
    // exactly like a staff member — this test constructs that realistic
    // configuration rather than asserting a role-based bypass the
    // algorithm does not have.
    public function test_active_admin_membership_accesses_every_business_in_workspace(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertTrue($this->manager()->userCanAccessBusiness($admin->id, $businessA));
        $this->assertTrue($this->manager()->userCanAccessBusiness($admin->id, $businessB));
    }

    // role is not consulted at all by §14.1 — an admin with scope=selected
    // and no grant has exactly the access a staff member in the same
    // configuration would have: none. This directly guards against
    // silently adding a role-based bypass the RFC does not specify.
    public function test_admin_role_does_not_bypass_selected_scope_with_no_grant(): void
    {
        $owner = $this->createCustomer();
        $admin = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $this->createMembership($workspace, $admin, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($admin->id, $business));
    }

    // 5. An active staff membership with scope=all has access to every Business.
    public function test_active_staff_scope_all_accesses_every_business_in_workspace(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);
        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertTrue($this->manager()->userCanAccessBusiness($staff->id, $businessA));
        $this->assertTrue($this->manager()->userCanAccessBusiness($staff->id, $businessB));
    }

    // 6/11. An active staff membership with scope=selected has access only
    // when the exact grant exists — a grant for another Business does not
    // leak access to the tested Business.
    public function test_active_staff_scope_selected_accesses_only_the_granted_business(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $granted = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $notGranted = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $granted);

        $this->assertTrue($this->manager()->userCanAccessBusiness($staff->id, $granted));
        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $notGranted));
    }

    // 7. Selected scope with zero grants provides access to no Businesses.
    public function test_selected_scope_with_zero_grants_denies_access(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    // 8. An inactive membership provides no Workspace-derived access.
    public function test_inactive_membership_provides_no_access(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => false,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    // 9. A membership in another Workspace provides no access.
    public function test_membership_in_another_workspace_provides_no_access(): void
    {
        $owner = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $user = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $this->createMembership($otherWorkspace, $user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($user->id, $business));
    }

    // 10. An unrelated User has no access.
    public function test_unrelated_user_has_no_access(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertFalse($this->manager()->userCanAccessBusiness($unrelated->id, $business));
    }

    // 12. A selected assignment row cannot override inactive membership state.
    public function test_selected_grant_cannot_override_inactive_membership(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => false,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);

        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    // 12. A selected assignment row cannot override inactive Workspace state.
    public function test_selected_grant_cannot_override_inactive_workspace(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user, ['is_active' => false]);
        $membership = $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);

        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    // 14. The method performs no write, event dispatch, or transition
    // creation. No Workspace domain event class exists yet in this
    // codebase (Slice 2A/2B deliberately add none), so the absence of any
    // ::dispatch() call is structurally guaranteed by userCanAccessBusiness()'s
    // source (a pure read); this test proves the write/transition side
    // directly, which is independently verifiable.
    public function test_evaluation_performs_no_write_event_or_transition(): void
    {
        $owner = $this->createCustomer();
        $staff = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);

        $before = [
            'workspace' => $workspace->fresh()->getAttributes(),
            'membership' => $membership->fresh()->getAttributes(),
            'business' => $business->fresh()->getAttributes(),
        ];

        $this->manager()->userCanAccessBusiness($staff->id, $business);
        $this->manager()->userCanAccessBusiness($owner->user_id, $business);
        $this->manager()->userCanAccessBusiness(999999, $business);

        $this->assertSame($before['workspace'], $workspace->fresh()->getAttributes());
        $this->assertSame($before['membership'], $membership->fresh()->getAttributes());
        $this->assertSame($before['business'], $business->fresh()->getAttributes());
        $this->assertSame(0, WorkspaceTransition::count());
    }

    // --- assertUserCanAccessBusiness() ---

    public function test_assert_does_not_throw_on_the_permitted_path(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->manager()->assertUserCanAccessBusiness($owner->user_id, $business);
        $this->assertTrue(true);
    }

    public function test_assert_throws_workspace_access_denied_on_the_denied_path(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->expectException(WorkspaceAccessDeniedException::class);
        $this->manager()->assertUserCanAccessBusiness($unrelated->id, $business);
    }

    public function test_assert_exception_exposes_expected_user_and_business_ids(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        try {
            $this->manager()->assertUserCanAccessBusiness($unrelated->id, $business);
            $this->fail('Expected WorkspaceAccessDeniedException was not thrown.');
        } catch (WorkspaceAccessDeniedException $e) {
            $this->assertSame($unrelated->id, $e->userId);
            $this->assertSame($business->id, $e->businessId);
        }
    }

    public function test_assert_creates_no_event_or_transition(): void
    {
        $owner = $this->createCustomer();
        $unrelated = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        try {
            $this->manager()->assertUserCanAccessBusiness($unrelated->id, $business);
        } catch (WorkspaceAccessDeniedException) {
            // expected
        }

        $this->assertSame(0, WorkspaceTransition::count());
    }
}
