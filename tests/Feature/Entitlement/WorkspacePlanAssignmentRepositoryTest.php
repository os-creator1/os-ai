<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Eloquent\EloquentWorkspacePlanAssignmentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

class WorkspacePlanAssignmentRepositoryTest extends TestCase
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
            EloquentWorkspacePlanAssignmentRepository::class,
            app(WorkspacePlanAssignmentRepository::class)
        );
    }

    public function test_create_persists_and_casts_status_and_is_complimentary(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $assignment = $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
            'is_complimentary' => true,
            'additional_business_slots' => 1,
        ]);

        $fresh = $assignment->fresh();
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $fresh->status);
        $this->assertTrue($fresh->is_complimentary);
        $this->assertSame(1, $fresh->additional_business_slots);
    }

    public function test_find_by_workspace_id_returns_the_exact_row(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $otherWorkspace = $this->createWorkspace($this->createCustomer()->user);

        $repository->create([
            'workspace_id' => $otherWorkspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
        ]);
        $assignment = $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
        ]);

        $found = $repository->findByWorkspaceId($workspace->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($assignment));
    }

    public function test_find_by_workspace_id_returns_null_when_unassigned(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->assertNull($repository->findByWorkspaceId($workspace->id));
    }

    public function test_duplicate_workspace_id_surfaces_as_a_query_exception(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
        ]);

        $this->expectException(QueryException::class);
        $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
        ]);
    }

    public function test_no_entitlement_or_slot_decision_method_exists_on_this_repository(): void
    {
        foreach (get_class_methods(EloquentWorkspacePlanAssignmentRepository::class) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('decide', $method);
            $this->assertStringNotContainsStringIgnoringCase('entitle', $method);
        }
    }

    public function test_update_persists_a_round_trip_change(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $assignment = $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
            'additional_business_slots' => 0,
        ]);

        $updated = $repository->update($assignment, ['additional_business_slots' => 2]);

        $this->assertSame(2, $updated->fresh()->additional_business_slots);
    }

    public function test_has_non_complimentary_for_catalog_for_update_reports_true_for_a_non_complimentary_reference(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
            'is_complimentary' => false,
        ]);

        $this->assertTrue($repository->hasNonComplimentaryForCatalogForUpdate($this->coreCatalogId()));
    }

    public function test_has_non_complimentary_for_catalog_for_update_reports_false_for_only_complimentary_references(): void
    {
        $repository = app(WorkspacePlanAssignmentRepository::class);
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $repository->create([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => WorkspacePlanAssignmentStatus::Active,
            'is_complimentary' => true,
        ]);

        $this->assertFalse($repository->hasNonComplimentaryForCatalogForUpdate($this->coreCatalogId()));
    }

    public function test_has_non_complimentary_for_catalog_for_update_uses_a_locking_read(): void
    {
        // Proves this is a genuine locking/current read, not an ordinary
        // consistent-snapshot exists() query: reflection confirms the
        // Eloquent implementation issues lockForUpdate() on this query,
        // matching WorkspaceManagerConcurrencyTest.php's own documented
        // rationale for why a plain snapshot read is insufficient here.
        $reflection = new \ReflectionMethod(EloquentWorkspacePlanAssignmentRepository::class, 'hasNonComplimentaryForCatalogForUpdate');
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

        $this->assertStringContainsString('lockForUpdate', $body, 'hasNonComplimentaryForCatalogForUpdate() must use a locking read (lockForUpdate()), never an ordinary consistent-snapshot exists() query.');
    }
}
