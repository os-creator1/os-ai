<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\Workspace\WorkspaceBackfillConflictException;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Workspace\Support\HistoricalWorkspaceTestCase;

/**
 * Loads and invokes the actual migration 5 class (RFC-003 §10.1), not a
 * reimplementation of its logic, by requiring the migration file directly
 * — the same mechanism Laravel's migrator uses for anonymous-class
 * migrations.
 */
#[Group('historical-m1a')]
class WorkspaceBackfillMigrationTest extends HistoricalWorkspaceTestCase
{
    private const MIGRATION_PATH = 'migrations/2026_07_30_120005_backfill_business_workspaces.php';

    private function migrationInstance(): object
    {
        return require database_path(self::MIGRATION_PATH);
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Legacy',
            'last_name' => 'Customer',
            'email' => 'legacy-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ], $overrides));
    }

    private function createNullWorkspaceBusiness(int $customerId, array $overrides = []): Business
    {
        return Business::create(array_merge([
            'customer_id' => $customerId,
            'name' => 'Legacy Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides));
    }

    private function assignWorkspace(Business $business, int $workspaceId): void
    {
        $business->workspace_id = $workspaceId;
        $business->save();
    }

    private function insertWorkspace(int $ownerUserId): int
    {
        return DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'Existing Workspace',
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // 1. up() backfills eligible Businesses using the approved WorkspaceBackfillV1 behavior.
    public function test_up_backfills_eligible_businesses(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        $this->migrationInstance()->up();

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $this->assertNotNull($workspaceId);
        $this->assertSame($user->id, DB::table('workspaces')->where('id', $workspaceId)->value('owner_user_id'));
    }

    // 2. up() reuses an existing single Workspace assignment.
    public function test_up_reuses_existing_single_workspace_assignment(): void
    {
        $user = $this->createUser();
        $assigned = $this->createNullWorkspaceBusiness($user->id, ['name' => 'Assigned']);
        $unassigned = $this->createNullWorkspaceBusiness($user->id, ['name' => 'Unassigned']);
        $workspaceId = $this->insertWorkspace($user->id);
        $this->assignWorkspace($assigned, $workspaceId);

        $this->migrationInstance()->up();

        $this->assertSame($workspaceId, DB::table('businesses')->where('id', $unassigned->id)->value('workspace_id'));
        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
    }

    // 3. a conflict propagates WorkspaceBackfillConflictException out of up().
    public function test_conflict_propagates_out_of_up(): void
    {
        $user = $this->createUser();
        $businessA = $this->createNullWorkspaceBusiness($user->id, ['name' => 'A']);
        $businessB = $this->createNullWorkspaceBusiness($user->id, ['name' => 'B']);
        $this->createNullWorkspaceBusiness($user->id, ['name' => 'C']); // stays null, triggers group selection

        $this->assignWorkspace($businessA, $this->insertWorkspace($user->id));
        $this->assignWorkspace($businessB, $this->insertWorkspace($user->id));

        $this->expectException(WorkspaceBackfillConflictException::class);
        $this->migrationInstance()->up();
    }

    // 4. down() does not null assignments or delete created/reused Workspaces.
    public function test_down_does_not_revert_assignments_or_delete_workspaces(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        $migration = $this->migrationInstance();
        $migration->up();

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $this->assertNotNull($workspaceId);

        $migration->down();

        $this->assertSame($workspaceId, DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
        $this->assertTrue(DB::table('workspaces')->where('id', $workspaceId)->exists());
    }

    // 5. migration execution does not invoke the workspaces:backfill Artisan command.
    public function test_migration_does_not_invoke_the_artisan_command(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        Artisan::shouldReceive('call')->never();
        Artisan::shouldReceive('queue')->never();

        $this->migrationInstance()->up();
    }

    // 6. no backfill algorithm is duplicated in the migration. Behavioral
    // proof of an *absence* of duplicated logic isn't possible, so this is
    // deliberately a narrow, migration-file-only source check (explicitly
    // reported per the Slice 5 instructions) — it never inspects
    // WorkspaceBackfillV1's own file.
    public function test_migration_does_not_duplicate_the_backfill_algorithm(): void
    {
        $source = file_get_contents(database_path(self::MIGRATION_PATH));

        $this->assertStringContainsString('WorkspaceBackfillV1', $source);
        $this->assertStringNotContainsString('lockForUpdate', $source);
        $this->assertStringNotContainsString('customer_id', $source);
        $this->assertStringNotContainsString("workspace_id'", $source);
    }
}
