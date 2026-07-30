<?php

namespace Tests\Feature\Workspace;

use App\Repositories\Contracts\WorkspaceRepository;
use App\Repositories\Eloquent\EloquentWorkspaceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

class WorkspaceRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(EloquentWorkspaceRepository::class, app(WorkspaceRepository::class));
    }

    public function test_create_generates_a_valid_uuid_when_uid_omitted(): void
    {
        $owner = $this->createCustomer()->user;
        $repository = app(WorkspaceRepository::class);

        $workspace = $repository->create([
            'name' => 'Owner Workspace',
            'owner_user_id' => $owner->id,
        ]);

        $this->assertTrue(Str::isUuid($workspace->uid));
    }

    public function test_create_preserves_an_explicitly_supplied_uid(): void
    {
        $owner = $this->createCustomer()->user;
        $repository = app(WorkspaceRepository::class);
        $uid = (string) Str::uuid();

        $workspace = $repository->create([
            'uid' => $uid,
            'name' => 'Owner Workspace',
            'owner_user_id' => $owner->id,
        ]);

        $this->assertSame($uid, $workspace->uid);
    }

    public function test_find_by_id_is_exact(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $otherWorkspace = $this->createWorkspace($this->createCustomer()->user);

        $found = $repository->findById($workspace->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($workspace));
        $this->assertFalse($found->is($otherWorkspace));
    }

    public function test_find_by_uid_is_exact(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->createWorkspace($this->createCustomer()->user);

        $found = $repository->findByUid($workspace->uid);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($workspace));
    }

    public function test_find_owned_by_returns_owned_workspaces_only(): void
    {
        $owner = $this->createCustomer()->user;
        $member = $this->createCustomer()->user;
        $repository = app(WorkspaceRepository::class);

        $owned = $this->createWorkspace($owner);
        $memberOf = $this->createWorkspace($member);
        $this->createMembership($memberOf, $owner);

        $result = $repository->findOwnedBy($owner->id);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($owned));
    }

    public function test_businesses_for_workspace_excludes_other_workspaces(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $repository = app(WorkspaceRepository::class);

        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherCustomer->user);

        $inWorkspace = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $this->createBusinessForCustomer($otherCustomer->user_id, $otherWorkspace->id);

        $result = $repository->businessesForWorkspace($workspace);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($inWorkspace));
    }

    public function test_update_persists_allowed_fields(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user, ['name' => 'Original Name']);

        $updated = $repository->update($workspace, ['name' => 'Renamed Workspace']);

        $this->assertSame('Renamed Workspace', $updated->fresh()->name);
    }

    public function test_set_active_persists_boolean_state(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user, ['is_active' => true]);

        $repository->setActive($workspace, false);

        $this->assertFalse($workspace->fresh()->is_active);
    }

    public function test_find_for_update_works_inside_a_real_transaction(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $found = DB::transaction(function () use ($repository, $workspace) {
            return $repository->findForUpdate($workspace->id);
        });

        $this->assertNotNull($found);
        $this->assertTrue($found->is($workspace));
        $this->assertSame($workspace->name, $found->fresh()->name);
    }
}
