<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\Workspace\MultipleBusinessesPerWorkspaceException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;
use Tests\TestCase;

/**
 * Implementation Contract 13 — behavioral proof of
 * enforce_workspace_business_one_to_one_constraint, loaded and invoked
 * directly (not a reimplementation of its logic), against a disposable
 * "enforcement" database created fresh for every test via the same
 * TemporaryTestDatabase primitive WorkspaceEnforcementMigrationTest
 * already uses for migration 6. Never runs against ultimatesms_testing:
 * MySQL DDL is not transactional, so a RefreshDatabase-style transaction
 * cannot roll back this migration's schema changes — every test gets its
 * own throwaway database instead, dropped unconditionally afterward.
 *
 * Unlike WorkspaceEnforcementMigrationTest (which needs a child-process
 * spawn to roll all the way back to a pre-migration-6 historical schema),
 * this migration only needs the schema state immediately before ITSELF —
 * everything through Contract 12 — so a same-process full migrate:fresh
 * followed by rolling back exactly this one (always-newest) migration is
 * sufficient, with no historical-schema machinery required.
 */
class WorkspaceBusinessOneToOneEnforcementTest extends TestCase
{
    private const MIGRATION_NAME = '2026_09_22_100001_enforce_workspace_business_one_to_one_constraint';

    private const MIGRATION_PATH = 'migrations/2026_09_22_100001_enforce_workspace_business_one_to_one_constraint.php';

    /**
     * Runs $callback inside a disposable, fully-migrated enforcement
     * database rolled back exactly one step (this Contract 13 migration
     * itself, always the newest in the chain), landing on the
     * authoritative "everything through Contract 12, nothing from
     * Contract 13" schema state — then temporarily makes that database
     * the application's default connection so the migration file's own
     * unqualified Schema::/DB::table() calls, and any application code
     * under test, target it exactly as they would in a real deployment.
     * The temporary database is always dropped afterward, success or
     * failure, and the default connection is always restored.
     */
    private function withPreContract13Database(callable $callback): mixed
    {
        return TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $namedConnection) use ($callback) {
            Artisan::call('migrate:fresh', ['--database' => $namedConnection, '--force' => true]);

            $this->rollbackContract13Migration($namedConnection);

            $originalDefault = config('database.default');
            config(['database.default' => $namedConnection]);

            try {
                return $callback($namedConnection);
            } finally {
                config(['database.default' => $originalDefault]);
            }
        });
    }

    /**
     * Rolls back exactly this Contract 13 migration, computed by name from
     * the migrations table (never a hard-coded --step, which would silently
     * stop meaning "this migration" the moment a later one is added) —
     * mirroring VerifiesEnforcementWorkspaceDatabase's own precedent
     * technique for migration 6.
     */
    private function rollbackContract13Migration(string $connection): void
    {
        $row = DB::connection($connection)->table('migrations')->where('migration', self::MIGRATION_NAME)->first();

        if ($row === null) {
            throw new RuntimeException('Refusing to prepare the enforcement database: Contract 13 migration is not applied after migrate:fresh.');
        }

        $stepCount = DB::connection($connection)->table('migrations')->where('id', '>=', $row->id)->count();

        Artisan::call('migrate:rollback', ['--database' => $connection, '--step' => $stepCount, '--force' => true]);

        if (DB::connection($connection)->table('migrations')->where('migration', self::MIGRATION_NAME)->exists()) {
            throw new RuntimeException('Refusing to run: Contract 13 migration is still applied after rollback.');
        }
    }

    private function migrationInstance(): object
    {
        return require database_path(self::MIGRATION_PATH);
    }

    private function indexExists(string $connection, string $indexName): bool
    {
        return DB::connection($connection)->table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    private function foreignKeyExists(string $connection): bool
    {
        return DB::connection($connection)->table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('CONSTRAINT_NAME', 'businesses_workspace_id_foreign')
            ->exists();
    }

    private function insertUser(string $connection, string $label = 'Enforcement'): int
    {
        return DB::connection($connection)->table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => $label,
            'last_name' => 'Test',
            'email' => 'enforcement13-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertWorkspace(string $connection, int $ownerUserId): int
    {
        return DB::connection($connection)->table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'Enforcement Workspace',
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBusiness(string $connection, int $customerId, int $workspaceId, string $name = 'Enforcement Biz'): int
    {
        return DB::connection($connection)->table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'status' => 'active',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // A. VIOLATION FAILS BEFORE DDL
    // ------------------------------------------------------------------

    public function test_up_throws_for_a_seeded_violation_and_applies_no_ddl(): void
    {
        $this->withPreContract13Database(function (string $connection) {
            $userId = $this->insertUser($connection);
            $workspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $workspaceId, 'First');
            $this->insertBusiness($connection, $userId, $workspaceId, 'Second');

            try {
                $this->migrationInstance()->up();
                $this->fail('Expected MultipleBusinessesPerWorkspaceException was not thrown.');
            } catch (MultipleBusinessesPerWorkspaceException $e) {
                $this->assertSame([$workspaceId], $e->workspaceIds);
            }

            $this->assertFalse($this->indexExists($connection, 'businesses_workspace_id_unique'), 'The unique constraint must not be partially applied.');
            $this->assertTrue($this->indexExists($connection, 'businesses_workspace_id_index'), 'The existing plain index must remain untouched.');
            $this->assertTrue($this->foreignKeyExists($connection), 'The existing FK must remain untouched.');

            // Existing schema remains usable: an insert against a
            // DIFFERENT, non-violating Workspace still works.
            $otherWorkspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $otherWorkspaceId, 'Still Works');
            $this->assertTrue(DB::connection($connection)->table('businesses')->where('workspace_id', $otherWorkspaceId)->exists());
        });
    }

    // ------------------------------------------------------------------
    // B. CLEAN DATABASE APPLIES SUCCESSFULLY
    // ------------------------------------------------------------------

    public function test_up_applies_cleanly_for_valid_one_business_per_workspace_data(): void
    {
        $this->withPreContract13Database(function (string $connection) {
            $userId = $this->insertUser($connection);
            $workspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $workspaceId);

            $this->migrationInstance()->up();

            $this->assertTrue($this->indexExists($connection, 'businesses_workspace_id_unique'));
            $this->assertFalse($this->indexExists($connection, 'businesses_workspace_id_index'), 'The now-redundant plain index must be dropped.');
            $this->assertTrue($this->indexExists($connection, 'businesses_workspace_id_status_index'), 'The unrelated composite index must survive untouched.');
            $this->assertTrue($this->foreignKeyExists($connection));
        });
    }

    // ------------------------------------------------------------------
    // C. TRUE DB-LEVEL ENFORCEMENT (the central acceptance proof)
    // ------------------------------------------------------------------

    public function test_a_raw_direct_insert_reusing_a_workspace_id_is_rejected_at_the_database_layer(): void
    {
        $this->withPreContract13Database(function (string $connection) {
            $userId = $this->insertUser($connection);
            $workspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $workspaceId, 'Original');

            $this->migrationInstance()->up();

            $this->expectException(QueryException::class);
            $this->insertBusiness($connection, $userId, $workspaceId, 'Duplicate Attempt');
        });
    }

    // ------------------------------------------------------------------
    // D. DIFFERENT WORKSPACE STILL WORKS
    // ------------------------------------------------------------------

    public function test_a_business_using_a_different_unused_workspace_remains_valid_after_enforcement(): void
    {
        $this->withPreContract13Database(function (string $connection) {
            $userId = $this->insertUser($connection);
            $workspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $workspaceId, 'First');

            $this->migrationInstance()->up();

            $otherWorkspaceId = $this->insertWorkspace($connection, $userId);
            $businessId = $this->insertBusiness($connection, $userId, $otherWorkspaceId, 'Second Workspace');

            $this->assertTrue(
                DB::connection($connection)->table('businesses')->where('id', $businessId)->where('workspace_id', $otherWorkspaceId)->exists()
            );
        });
    }

    // ------------------------------------------------------------------
    // E. DOWN() REVERSAL
    // ------------------------------------------------------------------

    public function test_down_restores_the_plain_index_and_removes_the_unique_constraint(): void
    {
        $this->withPreContract13Database(function (string $connection) {
            $userId = $this->insertUser($connection);
            $workspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $workspaceId, 'First');

            $migration = $this->migrationInstance();
            $migration->up();
            $migration->down();

            $this->assertFalse($this->indexExists($connection, 'businesses_workspace_id_unique'));
            $this->assertTrue($this->indexExists($connection, 'businesses_workspace_id_index'));
            $this->assertTrue($this->indexExists($connection, 'businesses_workspace_id_status_index'));
            $this->assertTrue($this->foreignKeyExists($connection), 'The FK must remain structurally valid throughout.');

            // A duplicate Workspace assignment can be inserted again --
            // proving the constraint genuinely reverted, not merely
            // renamed -- entirely inside this disposable database.
            $this->insertBusiness($connection, $userId, $workspaceId, 'Second Again');
            $this->assertSame(2, DB::connection($connection)->table('businesses')->where('workspace_id', $workspaceId)->count());
        });
    }

    // ------------------------------------------------------------------
    // F. RESIDUAL APPLICATION SEAM BACKSTOP
    //
    // WorkspaceManager::createBusinessInWorkspace() still exists (Contract
    // 14 removes it). Its own entitlement gate (assertCanCreateAnotherBusiness())
    // would ALSO block a second Business for an ordinary Core/Growth
    // Workspace, for an unrelated reason (business_slot_max), which would
    // prove nothing about the NEW DB constraint specifically. An
    // Agency-tier plan assignment's catalog row has unlimited_business_slots
    // = true, so the entitlement gate allows the create through regardless
    // of existing count -- exactly the legacy shape Contract 10 migrated
    // Agencies away from -- letting this test prove the DB constraint is
    // what actually stops it, a genuine defense-in-depth backstop even
    // against this un-removed application seam.
    // ------------------------------------------------------------------

    public function test_workspace_manager_create_business_in_workspace_is_blocked_at_the_db_layer_too(): void
    {
        $this->withPreContract13Database(function (string $connection) {
            $userId = $this->insertUser($connection);
            $customerId = DB::connection($connection)->table('customers')->insertGetId([
                'uid' => (string) Str::uuid(),
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $workspaceId = $this->insertWorkspace($connection, $userId);
            $this->insertBusiness($connection, $userId, $workspaceId, 'First');

            $agencyCatalogId = DB::connection($connection)->table('workspace_plan_catalog')->where('tier', 'agency')->value('id');
            $this->assertNotNull($agencyCatalogId, 'The Agency catalog row must exist after a full migrate:fresh.');

            DB::connection($connection)->table('workspace_plan_assignments')->insert([
                'workspace_id' => $workspaceId,
                'workspace_plan_catalog_id' => $agencyCatalogId,
                'status' => 'active',
                'is_complimentary' => true,
                'additional_business_slots' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->migrationInstance()->up();

            $customer = Customer::find($customerId);
            $workspace = Workspace::find($workspaceId);

            $this->expectException(QueryException::class);
            app(WorkspaceManager::class)->createBusinessInWorkspace($userId, $customer, $workspace, [
                'name' => 'Residual Seam Attempt',
                'industry' => 'photo_booth_service',
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'currency_code' => 'USD',
            ]);
        });
    }
}
