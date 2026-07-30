<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Models\WorkspaceMembershipBusiness;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Eloquent\EloquentWorkspaceMembershipBusinessRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

class WorkspaceMembershipBusinessRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspaceMembershipBusinessRepository::class,
            app(WorkspaceMembershipBusinessRepository::class)
        );
    }

    public function test_assigned_business_ids_is_scoped_to_one_membership(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);

        $membershipA = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $membershipB = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $businessA = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $repository->assign($membershipA, $businessA);
        $repository->assign($membershipB, $businessB);

        $ids = $repository->assignedBusinessIds($membershipA);

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($businessA->id));
        $this->assertFalse($ids->contains($businessB->id));
    }

    public function test_is_assigned_is_scoped_to_one_membership(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);

        $membershipA = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $membershipB = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $repository->assign($membershipA, $business);

        $this->assertTrue($repository->isAssigned($membershipA, $business->id));
        $this->assertFalse($repository->isAssigned($membershipB, $business->id));
    }

    public function test_assign_persists_a_same_workspace_grant(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $assignment = $repository->assign($membership, $business);

        $this->assertInstanceOf(WorkspaceMembershipBusiness::class, $assignment);
        $this->assertTrue($repository->isAssigned($membership, $business->id));
    }

    public function test_duplicate_assign_is_idempotent(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $first = $repository->assign($membership, $business);
        $second = $repository->assign($membership, $business);

        $this->assertTrue($first->is($second));
        $this->assertSame(
            1,
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)
                ->where('business_id', $business->id)
                ->count()
        );
    }

    public function test_assign_throws_cross_workspace_assignment_exception(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);

        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherCustomer->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $otherBusiness = $this->createBusinessForCustomer($otherCustomer->user_id, $otherWorkspace->id);

        $this->expectException(CrossWorkspaceAssignmentException::class);
        $repository->assign($membership, $otherBusiness);
    }

    public function test_sync_validates_every_id_before_any_write(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $validBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $missingId = $validBusiness->id + 999999;

        try {
            $repository->syncForMembership($membership, [$validBusiness->id, $missingId]);
            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException $e) {
            // expected
        }

        // The valid ID must not have been written either — every ID is
        // validated before any assignment row changes (RFC-003 §12.3).
        $this->assertFalse($repository->isAssigned($membership, $validBusiness->id));
        $this->assertSame(0, $repository->assignedBusinessIds($membership)->count());
    }

    public function test_sync_rejects_a_nonexistent_business_before_changing_assignments(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $existing = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $repository->assign($membership, $existing);

        $missingId = $existing->id + 999999;

        try {
            $repository->syncForMembership($membership, [$existing->id, $missingId]);
            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException $e) {
            // expected
        }

        $this->assertTrue($repository->isAssigned($membership, $existing->id));
        $this->assertSame(1, $repository->assignedBusinessIds($membership)->count());
    }

    public function test_sync_rejects_a_cross_workspace_business_before_changing_assignments(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);

        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherCustomer->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $existing = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $repository->assign($membership, $existing);
        $otherBusiness = $this->createBusinessForCustomer($otherCustomer->user_id, $otherWorkspace->id);

        try {
            $repository->syncForMembership($membership, [$existing->id, $otherBusiness->id]);
            $this->fail('Expected CrossWorkspaceAssignmentException was not thrown.');
        } catch (CrossWorkspaceAssignmentException $e) {
            // expected
        }

        $this->assertTrue($repository->isAssigned($membership, $existing->id));
        $this->assertSame(1, $repository->assignedBusinessIds($membership)->count());
    }

    public function test_sync_replaces_grants_atomically_after_successful_validation(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $keep = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $drop = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $add = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $repository->assign($membership, $keep);
        $repository->assign($membership, $drop);

        $result = $repository->syncForMembership($membership, [$keep->id, $add->id]);

        $resultIds = collect($result)->pluck('business_id');
        $this->assertCount(2, $resultIds);
        $this->assertTrue($resultIds->contains($keep->id));
        $this->assertTrue($resultIds->contains($add->id));
        $this->assertFalse($resultIds->contains($drop->id));

        $this->assertTrue($repository->isAssigned($membership, $keep->id));
        $this->assertTrue($repository->isAssigned($membership, $add->id));
        $this->assertFalse($repository->isAssigned($membership, $drop->id));
    }

    public function test_sync_with_empty_array_removes_all_grants(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $repository->assign($membership, $business);

        $result = $repository->syncForMembership($membership, []);

        $this->assertCount(0, $result);
        $this->assertSame(0, $repository->assignedBusinessIds($membership)->count());
    }

    public function test_unassign_removes_only_the_supplied_memberships_grant(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);

        $membershipA = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $membershipB = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $repository->assign($membershipA, $business);
        $repository->assign($membershipB, $business);

        $repository->unassign($membershipA, $business->id);

        $this->assertFalse($repository->isAssigned($membershipA, $business->id));
        $this->assertTrue($repository->isAssigned($membershipB, $business->id));
    }

    public function test_unassign_of_a_missing_grant_is_a_safe_no_op(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipBusinessRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $repository->unassign($membership, $business->id);

        $this->assertFalse($repository->isAssigned($membership, $business->id));
    }
}
