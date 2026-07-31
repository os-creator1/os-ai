<?php

namespace Tests\Feature\Workspace;

use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2B: WorkspaceRepository::allForUser().
 */
class WorkspaceRepositoryAllForUserTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_owned_workspace_is_included(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($owner->id);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($workspace));
    }

    public function test_active_member_workspace_is_included(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $member, ['is_active' => true]);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($member->id);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($workspace));
    }

    public function test_inactive_member_only_workspace_is_excluded(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $member, ['is_active' => false]);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($member->id);

        $this->assertCount(0, $result);
    }

    public function test_inactive_workspace_still_included_when_owned(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($owner->id);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($workspace));
        $this->assertFalse($result->first()->is_active);
    }

    public function test_inactive_workspace_still_included_through_active_membership(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);
        $this->createMembership($workspace, $member, ['is_active' => true]);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($member->id);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($workspace));
        $this->assertFalse($result->first()->is_active);
    }

    public function test_ownership_and_membership_paths_deduplicate(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        // Nothing prevents an owner from also holding a membership row at
        // the schema level; allForUser() must still return the Workspace once.
        $this->createMembership($workspace, $owner, ['is_active' => true]);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($owner->id);

        $this->assertCount(1, $result);
    }

    public function test_results_are_ordered_by_id_ascending(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;

        $workspaceA = $this->createWorkspace($owner);
        $workspaceB = $this->createWorkspace($this->createCustomer()->user);
        $this->createMembership($workspaceB, $member, ['is_active' => true]);
        $workspaceC = $this->createWorkspace($owner);

        // The member also owns nothing else here; combine both paths onto one user.
        $this->createMembership($workspaceA, $member, ['is_active' => true]);
        $this->createMembership($workspaceC, $member, ['is_active' => true]);

        $repository = app(WorkspaceRepository::class);
        $result = $repository->allForUser($member->id);

        $this->assertSame(
            [$workspaceA->id, $workspaceB->id, $workspaceC->id],
            $result->pluck('id')->all()
        );
    }

    public function test_unrelated_workspaces_are_excluded(): void
    {
        $owner = $this->createCustomer()->user;
        $unrelatedUser = $this->createCustomer()->user;
        $this->createWorkspace($owner);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($unrelatedUser->id);

        $this->assertCount(0, $result);
    }

    public function test_accepts_a_plain_integer_user_id(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $repository = app(WorkspaceRepository::class);

        $rawId = (int) $owner->id;
        $result = $repository->allForUser($rawId);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($workspace));
    }

    public function test_return_type_is_a_collection_of_workspace_models(): void
    {
        $owner = $this->createCustomer()->user;
        $this->createWorkspace($owner);
        $repository = app(WorkspaceRepository::class);

        $result = $repository->allForUser($owner->id);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertInstanceOf(Workspace::class, $result->first());
    }
}
