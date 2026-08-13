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

    public function test_transfer_ownership_updates_only_owner_user_id(): void
    {
        $repository = app(WorkspaceRepository::class);
        $originalOwner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($originalOwner, ['name' => 'Original Name', 'is_active' => true]);

        $updated = $repository->transferOwnership($workspace, $newOwner->id);

        $this->assertSame($newOwner->id, $updated->owner_user_id);
        $this->assertSame($newOwner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_transfer_ownership_leaves_name_and_active_state_untouched(): void
    {
        $repository = app(WorkspaceRepository::class);
        $originalOwner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($originalOwner, ['name' => 'Original Name', 'is_active' => true]);

        $repository->transferOwnership($workspace, $newOwner->id);
        $fresh = $workspace->fresh();

        $this->assertSame('Original Name', $fresh->name);
        $this->assertTrue($fresh->is_active);
    }

    public function test_transfer_ownership_returns_the_same_refreshed_workspace(): void
    {
        $repository = app(WorkspaceRepository::class);
        $originalOwner = $this->createCustomer()->user;
        $newOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($originalOwner);

        $updated = $repository->transferOwnership($workspace, $newOwner->id);

        $this->assertTrue($updated->is($workspace));
        $this->assertTrue($updated->exists);
    }

    // --- paginateForAdmin() (RFC-003 Milestone 5) ---------------------------

    public function test_paginate_for_admin_returns_workspaces_across_unrelated_tenants(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspaceA = $this->createWorkspace($this->createCustomer()->user);
        $workspaceB = $this->createWorkspace($this->createCustomer()->user);

        $result = $repository->paginateForAdmin([], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($workspace) => $workspace->is($workspaceA)));
        $this->assertTrue($result->getCollection()->contains(fn ($workspace) => $workspace->is($workspaceB)));
    }

    public function test_paginate_for_admin_includes_active_and_inactive_workspaces(): void
    {
        $repository = app(WorkspaceRepository::class);
        $active = $this->createWorkspace($this->createCustomer()->user, ['is_active' => true]);
        $inactive = $this->createWorkspace($this->createCustomer()->user, ['is_active' => false]);

        $result = $repository->paginateForAdmin([], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($workspace) => $workspace->is($active)));
        $this->assertTrue($result->getCollection()->contains(fn ($workspace) => $workspace->is($inactive)));
    }

    public function test_paginate_for_admin_eager_loads_owner(): void
    {
        $repository = app(WorkspaceRepository::class);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $result = $repository->paginateForAdmin([], 25);
        $found = $result->getCollection()->first(fn ($item) => $item->is($workspace));

        $this->assertTrue($found->relationLoaded('owner'));
        $this->assertTrue($found->owner->is($owner));
    }

    public function test_paginate_for_admin_businesses_count_is_correct(): void
    {
        $repository = app(WorkspaceRepository::class);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->createBusinessForCustomer($owner->id, $workspace->id);

        $result = $repository->paginateForAdmin([], 25);
        $found = $result->getCollection()->first(fn ($item) => $item->is($workspace));

        $this->assertSame(2, $found->businesses_count);
    }

    public function test_paginate_for_admin_active_memberships_count_excludes_inactive(): void
    {
        $repository = app(WorkspaceRepository::class);
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $this->createCustomer()->user, ['is_active' => true]);
        $this->createMembership($workspace, $this->createCustomer()->user, ['is_active' => true]);
        $this->createMembership($workspace, $this->createCustomer()->user, ['is_active' => false]);

        $result = $repository->paginateForAdmin([], 25);
        $found = $result->getCollection()->first(fn ($item) => $item->is($workspace));

        $this->assertSame(2, $found->active_memberships_count);
    }

    public function test_paginate_for_admin_search_matches_workspace_name(): void
    {
        $repository = app(WorkspaceRepository::class);
        $matching = $this->createWorkspace($this->createCustomer()->user, ['name' => 'Acme Ops']);
        $other = $this->createWorkspace($this->createCustomer()->user, ['name' => 'Unrelated Co']);

        $result = $repository->paginateForAdmin(['search' => 'Acme'], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($item) => $item->is($matching)));
        $this->assertFalse($result->getCollection()->contains(fn ($item) => $item->is($other)));
    }

    public function test_paginate_for_admin_search_matches_workspace_uid(): void
    {
        $repository = app(WorkspaceRepository::class);
        $matching = $this->createWorkspace($this->createCustomer()->user);
        $other = $this->createWorkspace($this->createCustomer()->user);

        $result = $repository->paginateForAdmin(['search' => $matching->uid], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($item) => $item->is($matching)));
        $this->assertFalse($result->getCollection()->contains(fn ($item) => $item->is($other)));
    }

    public function test_paginate_for_admin_active_filter_true(): void
    {
        $repository = app(WorkspaceRepository::class);
        $active = $this->createWorkspace($this->createCustomer()->user, ['is_active' => true]);
        $inactive = $this->createWorkspace($this->createCustomer()->user, ['is_active' => false]);

        $result = $repository->paginateForAdmin(['is_active' => true], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($item) => $item->is($active)));
        $this->assertFalse($result->getCollection()->contains(fn ($item) => $item->is($inactive)));
    }

    public function test_paginate_for_admin_active_filter_false(): void
    {
        $repository = app(WorkspaceRepository::class);
        $active = $this->createWorkspace($this->createCustomer()->user, ['is_active' => true]);
        $inactive = $this->createWorkspace($this->createCustomer()->user, ['is_active' => false]);

        $result = $repository->paginateForAdmin(['is_active' => false], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($item) => $item->is($inactive)));
        $this->assertFalse($result->getCollection()->contains(fn ($item) => $item->is($active)));
    }

    public function test_paginate_for_admin_ignores_unknown_filters(): void
    {
        $repository = app(WorkspaceRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $result = $repository->paginateForAdmin(['unknown_filter' => 'anything'], 25);

        $this->assertTrue($result->getCollection()->contains(fn ($item) => $item->is($workspace)));
    }

    public function test_paginate_for_admin_orders_by_id_descending(): void
    {
        $repository = app(WorkspaceRepository::class);
        $first = $this->createWorkspace($this->createCustomer()->user);
        $second = $this->createWorkspace($this->createCustomer()->user);
        $third = $this->createWorkspace($this->createCustomer()->user);

        $result = $repository->paginateForAdmin([], 25);

        $this->assertSame([$third->id, $second->id, $first->id], $result->getCollection()->pluck('id')->all());
    }

    public function test_paginate_for_admin_clamps_per_page_to_one_hundred(): void
    {
        $repository = app(WorkspaceRepository::class);
        $this->createWorkspace($this->createCustomer()->user);

        $result = $repository->paginateForAdmin([], 500);

        $this->assertSame(100, $result->perPage());
    }
}
