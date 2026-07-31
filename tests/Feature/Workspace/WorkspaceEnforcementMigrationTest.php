<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\Workspace\DanglingWorkspaceReferenceException;
use App\Exceptions\Workspace\WorkspaceBackfillIncompleteException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Workspace\Support\EnforcementWorkspaceTestCase;

/**
 * Behavioral proof of enforce_business_workspace_constraint (migration 6,
 * RFC-003 §10.6 step 5, §12.4, §21.5), loaded and invoked directly — not a
 * reimplementation of its logic — against a disposable enforcement
 * database prepared fresh before every test
 * (Support\EnforcementWorkspaceTestCase). Never runs against
 * ultimatesms_testing.
 */
#[Group('workspace-enforcement')]
class WorkspaceEnforcementMigrationTest extends EnforcementWorkspaceTestCase
{
    private const MIGRATION_PATH = 'migrations/2026_07_30_120006_enforce_business_workspace_constraint.php';

    private function migrationInstance(): object
    {
        return require database_path(self::MIGRATION_PATH);
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Enforcement',
            'last_name' => 'Test',
            'email' => 'enforcement-' . uniqid('', true) . '@example.test',
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
            'name' => 'Enforcement Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides));
    }

    private function insertWorkspace(int $ownerUserId, array $overrides = []): int
    {
        return DB::table('workspaces')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'name' => 'Existing Workspace',
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function assignWorkspace(Business $business, int $workspaceId): void
    {
        $business->workspace_id = $workspaceId;
        $business->save();
    }

    private function assertWorkspaceIdColumnIsNullable(bool $expectedNullable): void
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'workspace_id'"
        );

        $this->assertSame($expectedNullable ? 'YES' : 'NO', $column->IS_NULLABLE);
    }

    private function foreignKeyExists(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('COLUMN_NAME', 'workspace_id')
            ->where('CONSTRAINT_NAME', 'businesses_workspace_id_foreign')
            ->exists();
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    // 1/2/3. up() throws WorkspaceBackfillIncompleteException for a remaining null row, exposes the
    // exact count, and applies no DDL before failing.
    public function test_up_throws_incomplete_exception_when_a_null_business_remains(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        try {
            $this->migrationInstance()->up();
            $this->fail('Expected WorkspaceBackfillIncompleteException was not thrown.');
        } catch (WorkspaceBackfillIncompleteException $e) {
            $this->assertSame(1, $e->remainingNullCount);
        }

        $this->assertWorkspaceIdColumnIsNullable(true);
        $this->assertFalse($this->foreignKeyExists());
        $this->assertFalse($this->indexExists('businesses_workspace_id_index'));
    }

    // 4/5/6. up() throws DanglingWorkspaceReferenceException for dangling workspace_id references,
    // exposes normalized (unique, ascending) IDs, and applies no DDL before failing.
    public function test_up_throws_dangling_reference_exception_for_missing_workspace_ids(): void
    {
        $user = $this->createUser();
        $businessA = $this->createNullWorkspaceBusiness($user->id, ['name' => 'A']);
        $businessB = $this->createNullWorkspaceBusiness($user->id, ['name' => 'B']);
        $businessC = $this->createNullWorkspaceBusiness($user->id, ['name' => 'C']);
        // Two Businesses share one dangling ID, a third references a
        // different dangling ID — the exception must dedupe and sort.
        DB::table('businesses')->where('id', $businessA->id)->update(['workspace_id' => 999999]);
        DB::table('businesses')->where('id', $businessB->id)->update(['workspace_id' => 999999]);
        DB::table('businesses')->where('id', $businessC->id)->update(['workspace_id' => 888888]);

        try {
            $this->migrationInstance()->up();
            $this->fail('Expected DanglingWorkspaceReferenceException was not thrown.');
        } catch (DanglingWorkspaceReferenceException $e) {
            $this->assertSame([888888, 999999], $e->workspaceIds);
        }

        $this->assertWorkspaceIdColumnIsNullable(true);
        $this->assertFalse($this->foreignKeyExists());
        $this->assertFalse($this->indexExists('businesses_workspace_id_index'));
    }

    // 7-13. A valid up() makes workspace_id NOT NULL and creates the exact expected index/FK shape.
    public function test_up_enforces_not_null_and_creates_the_expected_indexes_and_foreign_key(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $business = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($business, $workspaceId);

        $this->migrationInstance()->up();

        $this->assertWorkspaceIdColumnIsNullable(false);
        $this->assertTrue($this->indexExists('businesses_workspace_id_index'));
        $this->assertTrue($this->indexExists('businesses_workspace_id_status_index'));

        $compositeColumns = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('INDEX_NAME', 'businesses_workspace_id_status_index')
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();
        $this->assertSame(['workspace_id', 'status'], $compositeColumns);

        $foreignKey = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('CONSTRAINT_NAME', 'businesses_workspace_id_foreign')
            ->first();
        $this->assertNotNull($foreignKey);
        $this->assertSame('workspaces', $foreignKey->REFERENCED_TABLE_NAME);
        $this->assertSame('id', $foreignKey->REFERENCED_COLUMN_NAME);

        $deleteRule = DB::selectOne(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'"
        );
        $this->assertSame('RESTRICT', $deleteRule->DELETE_RULE);
    }

    // 14. deleting a referenced Workspace fails with a real QueryException.
    public function test_deleting_a_referenced_workspace_fails_after_enforcement(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $business = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($business, $workspaceId);

        $this->migrationInstance()->up();

        $this->expectException(QueryException::class);
        DB::table('workspaces')->where('id', $workspaceId)->delete();
    }

    // 15. raw Business insertion without workspace_id fails at the database boundary.
    public function test_business_insert_without_workspace_id_fails_after_enforcement(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $business = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($business, $workspaceId);

        $this->migrationInstance()->up();

        $this->expectException(QueryException::class);
        DB::table('businesses')->insert([
            'uid' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'name' => 'No Workspace',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'status' => 'draft',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // 16. createForCustomerInWorkspace() continues to succeed after enforcement.
    public function test_create_for_customer_in_workspace_still_succeeds_after_enforcement(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $seedBusiness = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($seedBusiness, $workspaceId);

        $this->migrationInstance()->up();

        $customer = Customer::create(['user_id' => $user->id]);
        $workspace = Workspace::find($workspaceId);

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Post-Enforcement Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);

        $this->assertSame($workspaceId, $business->workspace_id);
        $this->assertSame($user->id, $business->customer_id);
    }

    // 17. customer_id may differ from the Workspace's owner_user_id.
    public function test_customer_id_may_differ_from_workspace_owner_after_enforcement(): void
    {
        $businessOwner = $this->createUser();
        $workspaceOwner = $this->createUser();
        $workspaceId = $this->insertWorkspace($workspaceOwner->id);
        $seedBusiness = $this->createNullWorkspaceBusiness($businessOwner->id);
        $this->assignWorkspace($seedBusiness, $workspaceId);

        $this->migrationInstance()->up();

        $customer = Customer::create(['user_id' => $businessOwner->id]);
        $workspace = Workspace::find($workspaceId);

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Cross-Owner Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);

        $this->assertSame($businessOwner->id, $business->customer_id);
        $this->assertSame($workspaceId, $business->workspace_id);
        $this->assertNotSame($workspaceOwner->id, $business->customer_id);
    }

    // 18. an inactive Workspace remains valid for Business persistence.
    public function test_inactive_workspace_is_accepted_for_business_persistence_after_enforcement(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id, ['is_active' => false]);
        $seedBusiness = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($seedBusiness, $workspaceId);

        $this->migrationInstance()->up();

        $customer = Customer::create(['user_id' => $user->id]);
        $workspace = Workspace::find($workspaceId);

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Inactive WS Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);

        $this->assertSame($workspaceId, $business->workspace_id);
    }

    // 19/20/21. down() removes the FK, removes both final indexes, and restores workspace_id nullable.
    public function test_down_removes_fk_and_indexes_and_restores_nullable(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $business = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($business, $workspaceId);

        $migration = $this->migrationInstance();
        $migration->up();
        $migration->down();

        $this->assertWorkspaceIdColumnIsNullable(true);
        $this->assertFalse($this->foreignKeyExists());
        $this->assertFalse($this->indexExists('businesses_workspace_id_index'));
        $this->assertFalse($this->indexExists('businesses_workspace_id_status_index'));
    }

    // 22. down() preserves every existing workspace_id value, deletes no Business/Workspace, and
    // leaves customer_id untouched.
    public function test_down_preserves_existing_workspace_id_values(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $business = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($business, $workspaceId);

        $migration = $this->migrationInstance();
        $migration->up();
        $migration->down();

        $this->assertSame($workspaceId, DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
        $this->assertTrue(DB::table('workspaces')->where('id', $workspaceId)->exists());
        $this->assertSame($user->id, DB::table('businesses')->where('id', $business->id)->value('customer_id'));
    }

    // 23. a second up() after down() succeeds.
    public function test_second_up_after_down_succeeds(): void
    {
        $user = $this->createUser();
        $workspaceId = $this->insertWorkspace($user->id);
        $business = $this->createNullWorkspaceBusiness($user->id);
        $this->assignWorkspace($business, $workspaceId);

        $migration = $this->migrationInstance();
        $migration->up();
        $migration->down();
        $migration->up();

        $this->assertWorkspaceIdColumnIsNullable(false);
        $this->assertTrue($this->foreignKeyExists());
    }

    // 24. the full migration chain 1-6 succeeds on an empty enforcement database.
    public function test_full_migration_chain_succeeds_on_an_empty_enforcement_database(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $applied = DB::table('migrations')
            ->where('migration', 'like', '%enforce_business_workspace_constraint%')
            ->exists();

        $this->assertTrue($applied);
        $this->assertWorkspaceIdColumnIsNullable(false);
        $this->assertTrue($this->foreignKeyExists());
    }

    // 25. migration 5 precedes migration 6 in the migrations table.
    public function test_migration_five_precedes_migration_six_in_the_migrations_table(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $five = DB::table('migrations')->where('migration', 'like', '%backfill_business_workspaces%')->first();
        $six = DB::table('migrations')->where('migration', 'like', '%enforce_business_workspace_constraint%')->first();

        $this->assertNotNull($five);
        $this->assertNotNull($six);
        $this->assertTrue(
            $five->batch < $six->batch || ($five->batch === $six->batch && $five->id < $six->id),
            'Expected migration 5 to precede migration 6 in the migrations table.'
        );
    }
}
