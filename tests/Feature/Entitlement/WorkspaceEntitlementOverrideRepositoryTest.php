<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository;
use App\Repositories\Eloquent\EloquentWorkspaceEntitlementOverrideRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

class WorkspaceEntitlementOverrideRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspaceEntitlementOverrideRepository::class,
            app(WorkspaceEntitlementOverrideRepository::class)
        );
    }

    public function test_create_persists_and_casts_feature_key_and_state(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $override = $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'white_label',
            'state' => WorkspaceEntitlementOverrideState::Allow,
            'reason' => 'Sales exception',
            'created_by_user_id' => 1,
        ]);

        $fresh = $override->fresh();
        $this->assertSame(PlatformFeature::WhiteLabel, $fresh->feature_key);
        $this->assertSame(WorkspaceEntitlementOverrideState::Allow, $fresh->state);
    }

    public function test_find_by_workspace_and_feature_returns_the_exact_row(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $override = $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'white_label',
            'state' => WorkspaceEntitlementOverrideState::Deny,
        ]);

        $found = $repository->findByWorkspaceAndFeature($workspace->id, 'white_label');

        $this->assertNotNull($found);
        $this->assertTrue($found->is($override));
    }

    public function test_find_by_workspace_and_feature_returns_null_when_absent(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->assertNull($repository->findByWorkspaceAndFeature($workspace->id, 'white_label'));
    }

    public function test_delete_removes_the_row_reverting_to_inherit(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $override = $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'white_label',
            'state' => WorkspaceEntitlementOverrideState::Allow,
        ]);

        $repository->delete($override);

        $this->assertNull($repository->findByWorkspaceAndFeature($workspace->id, 'white_label'));
    }

    public function test_create_rejects_an_unknown_feature_key(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'not_a_real_feature',
            'state' => WorkspaceEntitlementOverrideState::Allow,
        ]);
    }

    public function test_duplicate_workspace_and_feature_key_surfaces_as_a_query_exception(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'white_label',
            'state' => WorkspaceEntitlementOverrideState::Allow,
        ]);

        $this->expectException(QueryException::class);
        $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'white_label',
            'state' => WorkspaceEntitlementOverrideState::Deny,
        ]);
    }

    public function test_no_entitlement_decision_method_exists_on_this_repository(): void
    {
        foreach (get_class_methods(EloquentWorkspaceEntitlementOverrideRepository::class) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('decide', $method);
            $this->assertStringNotContainsStringIgnoringCase('effectiveentitlement', $method);
        }
    }

    public function test_update_persists_a_round_trip_state_change(): void
    {
        $repository = app(WorkspaceEntitlementOverrideRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $override = $repository->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'white_label',
            'state' => WorkspaceEntitlementOverrideState::Allow,
        ]);

        $updated = $repository->update($override, WorkspaceEntitlementOverrideState::Deny);

        $this->assertSame(WorkspaceEntitlementOverrideState::Deny, $updated->fresh()->state);
    }
}
