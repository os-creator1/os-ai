<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use App\Repositories\Eloquent\EloquentWorkspaceEntitlementTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Append-only (RFC-004 §10.4/§21) — deliberately no update() method,
 * mirroring WorkspaceTransitionRepositoryTest's exact precedent (RFC-003).
 */
class WorkspaceEntitlementTransitionRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function coreCatalogId(): int
    {
        return (int) WorkspacePlanCatalog::where('tier', 'core')->value('id');
    }

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspaceEntitlementTransitionRepository::class,
            app(WorkspaceEntitlementTransitionRepository::class)
        );
    }

    public function test_create_persists_and_casts_transition_type(): void
    {
        $repository = app(WorkspaceEntitlementTransitionRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $transition = $repository->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceEntitlementTransitionType::PlanAssigned,
            'actor_user_id' => null,
            'to_plan_catalog_id' => $this->coreCatalogId(),
            'reason' => 'Milestone 1 backfill',
        ]);

        $this->assertSame(WorkspaceEntitlementTransitionType::PlanAssigned, $transition->fresh()->transition_type);
        $this->assertSame($this->coreCatalogId(), $transition->fresh()->to_plan_catalog_id);
    }

    public function test_for_workspace_returns_only_that_workspaces_rows_in_order(): void
    {
        $repository = app(WorkspaceEntitlementTransitionRepository::class);
        $workspaceA = $this->createWorkspace($this->createCustomer()->user);
        $workspaceB = $this->createWorkspace($this->createCustomer()->user);

        $repository->create([
            'workspace_id' => $workspaceB->id,
            'transition_type' => WorkspaceEntitlementTransitionType::PlanAssigned,
            'to_plan_catalog_id' => $this->coreCatalogId(),
        ]);
        $first = $repository->create([
            'workspace_id' => $workspaceA->id,
            'transition_type' => WorkspaceEntitlementTransitionType::PlanAssigned,
            'to_plan_catalog_id' => $this->coreCatalogId(),
        ]);
        $second = $repository->create([
            'workspace_id' => $workspaceA->id,
            'transition_type' => WorkspaceEntitlementTransitionType::PlanStatusChanged,
            'from_status' => 'active',
            'to_status' => 'suspended',
        ]);

        $result = $repository->forWorkspace($workspaceA->id);

        $this->assertCount(2, $result);
        $this->assertTrue($result->first()->is($first));
        $this->assertTrue($result->last()->is($second));
    }

    public function test_no_update_method_exists_on_this_repository(): void
    {
        $reflection = new ReflectionClass(EloquentWorkspaceEntitlementTransitionRepository::class);

        $this->assertFalse($reflection->hasMethod('update'));
    }

    public function test_no_entitlement_decision_method_exists_on_this_repository(): void
    {
        foreach (get_class_methods(EloquentWorkspaceEntitlementTransitionRepository::class) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('decide', $method);
        }
    }
}
