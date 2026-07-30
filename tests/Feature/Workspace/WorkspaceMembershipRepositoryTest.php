<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Eloquent\EloquentWorkspaceMembershipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

class WorkspaceMembershipRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspaceMembershipRepository::class,
            app(WorkspaceMembershipRepository::class)
        );
    }

    public function test_create_persists_explicit_role_and_scope_enum_values(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $member = $this->createCustomer()->user;

        $membership = $repository->create(
            $workspace,
            $member->id,
            WorkspaceMembershipRole::Admin,
            WorkspaceBusinessAccessScope::Selected
        );

        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->fresh()->role);
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->fresh()->business_access_scope);
    }

    public function test_creating_without_a_scope_is_impossible_through_the_repository_method_signature(): void
    {
        $method = new ReflectionMethod(EloquentWorkspaceMembershipRepository::class, 'create');
        $scopeParam = collect($method->getParameters())->firstWhere('name', 'scope');

        $this->assertNotNull($scopeParam);
        $this->assertFalse($scopeParam->isOptional());
        $this->assertFalse($scopeParam->allowsNull());
        $this->assertSame(WorkspaceBusinessAccessScope::class, $scopeParam->getType()?->getName());
    }

    public function test_duplicate_create_does_not_leak_an_unhandled_database_exception(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $member = $this->createCustomer()->user;

        $first = $repository->create($workspace, $member->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $second = $repository->create($workspace, $member->id, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);

        $this->assertTrue($first->is($second));
        $this->assertSame(
            1,
            WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $member->id)->count()
        );
    }

    public function test_find_by_workspace_and_user_cannot_return_another_workspaces_membership(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $member = $this->createCustomer()->user;

        $workspaceA = $this->createWorkspace($this->createCustomer()->user);
        $workspaceB = $this->createWorkspace($this->createCustomer()->user);

        $this->createMembership($workspaceB, $member);

        $this->assertNull($repository->findByWorkspaceAndUser($workspaceA, $member->id));
        $this->assertNotNull($repository->findByWorkspaceAndUser($workspaceB, $member->id));
    }

    public function test_active_for_workspace_excludes_inactive_rows(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $activeMember = $this->createCustomer()->user;
        $inactiveMember = $this->createCustomer()->user;

        $this->createMembership($workspace, $activeMember, ['is_active' => true]);
        $this->createMembership($workspace, $inactiveMember, ['is_active' => false]);

        $result = $repository->activeForWorkspace($workspace);

        $this->assertCount(1, $result);
        $this->assertSame($activeMember->id, $result->first()->user_id);
    }

    public function test_active_for_user_excludes_inactive_and_spans_workspaces(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $member = $this->createCustomer()->user;

        $workspaceA = $this->createWorkspace($this->createCustomer()->user);
        $workspaceB = $this->createWorkspace($this->createCustomer()->user);

        $this->createMembership($workspaceA, $member, ['is_active' => true]);
        $this->createMembership($workspaceB, $member, ['is_active' => false]);

        $result = $repository->activeForUser($member->id);

        $this->assertCount(1, $result);
        $this->assertSame($workspaceA->id, $result->first()->workspace_id);
    }

    public function test_update_role_persists_enum_value(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'role' => WorkspaceMembershipRole::Staff,
        ]);

        $repository->updateRole($membership, WorkspaceMembershipRole::Admin);

        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->fresh()->role);
    }

    public function test_update_business_access_scope_persists_enum_value(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);

        $repository->updateBusinessAccessScope($membership, WorkspaceBusinessAccessScope::Selected);

        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->fresh()->business_access_scope);
    }

    public function test_set_active_persists_state(): void
    {
        $repository = app(WorkspaceMembershipRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, ['is_active' => true]);

        $repository->setActive($membership, false);

        $this->assertFalse($membership->fresh()->is_active);
    }
}
