<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Loads and invokes the actual migration 8 class (RFC-004 §25.1 step H),
 * not a reimplementation of its logic, by requiring the migration file
 * directly — the same mechanism Laravel's migrator uses for anonymous-class
 * migrations, mirroring RFC-003's WorkspaceBackfillMigrationTest exactly.
 */
class WorkspaceEntitlementBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const MIGRATION_PATH = 'migrations/2026_08_13_120008_backfill_workspace_entitlement_assignments.php';

    private const MIGRATION_7_PATH = 'migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php';

    private function migrationInstance(): object
    {
        return require database_path(self::MIGRATION_PATH);
    }

    private function migration7Instance(): object
    {
        return require database_path(self::MIGRATION_7_PATH);
    }

    // 1. up() backfills an eligible Workspace using the approved WorkspaceEntitlementBackfillV1 behavior.
    public function test_up_backfills_an_eligible_workspace(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->migrationInstance()->up();

        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame('active', $assignment->status);
    }

    // 2. down() does not revert the assignment or the transition row.
    public function test_down_does_not_revert_assignment_or_transition(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $migration = $this->migrationInstance();
        $migration->up();

        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->count());

        $migration->down();

        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->count());
    }

    // 3. migration execution does not invoke the workspaces:backfill-entitlements Artisan command.
    public function test_migration_does_not_invoke_the_artisan_command(): void
    {
        $this->createWorkspace($this->createCustomer()->user);

        Artisan::shouldReceive('call')->never();
        Artisan::shouldReceive('queue')->never();

        $this->migrationInstance()->up();
    }

    // 4. no backfill algorithm is duplicated in the migration — a narrow,
    // migration-file-only source check, mirroring RFC-003's identical
    // discipline; it never inspects WorkspaceEntitlementBackfillV1's own
    // file.
    public function test_migration_does_not_duplicate_the_backfill_algorithm(): void
    {
        $source = file_get_contents(database_path(self::MIGRATION_PATH));

        $this->assertStringContainsString('WorkspaceEntitlementBackfillV1', $source);
        $this->assertStringNotContainsString('insertOrIgnore', $source);
        $this->assertStringNotContainsString("businesses'", $source);
    }

    // 5. migration 7's down() is a non-destructive no-op even after a real
    // backfill has created an assignment referencing the Core catalog row
    // (M1 correction round 2) — a backfilled assignment's
    // workspace_plan_catalog_id foreign key RESTRICTs deletion of that
    // catalog row, so down() must never attempt to delete
    // workspace_plan_catalog/workspace_plan_features rows. A subsequent
    // up() rerun must also be idempotent: no duplicate tiers, no duplicate
    // feature mappings, and the existing assignment remains valid
    // throughout.
    public function test_migration_7_down_is_non_destructive_and_up_rerun_is_idempotent(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        (new WorkspaceEntitlementBackfillV1())->run();

        $catalogCountBefore = DB::table('workspace_plan_catalog')->count();
        $featureCountBefore = DB::table('workspace_plan_features')->count();
        $coreId = DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');

        $assignmentBefore = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($assignmentBefore);
        $this->assertSame($coreId, $assignmentBefore->workspace_plan_catalog_id);

        $migration7 = $this->migration7Instance();

        // down() must not throw, and must not touch the catalog/feature rows.
        $migration7->down();

        $this->assertSame($catalogCountBefore, DB::table('workspace_plan_catalog')->count());
        $this->assertSame($featureCountBefore, DB::table('workspace_plan_features')->count());
        $this->assertTrue(DB::table('workspace_plan_catalog')->where('id', $coreId)->exists());

        $assignmentAfterDown = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($assignmentAfterDown);
        $this->assertSame($coreId, $assignmentAfterDown->workspace_plan_catalog_id);

        // A rerun of up() must be idempotent: identical row counts, no
        // duplicate tier, no duplicate feature mapping, existing assignment
        // still valid.
        $migration7->up();

        $this->assertSame($catalogCountBefore, DB::table('workspace_plan_catalog')->count());
        $this->assertSame($featureCountBefore, DB::table('workspace_plan_features')->count());
        $this->assertSame(1, DB::table('workspace_plan_catalog')->where('tier', 'core')->count());

        $assignmentAfterRerun = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($assignmentAfterRerun);
        $this->assertSame($coreId, $assignmentAfterRerun->workspace_plan_catalog_id);

        $transitionCount = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', WorkspaceEntitlementTransitionType::PlanAssigned->value)
            ->count();
        $this->assertSame(1, $transitionCount);
    }
}
