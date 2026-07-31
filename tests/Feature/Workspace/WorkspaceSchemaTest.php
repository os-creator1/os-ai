<?php

namespace Tests\Feature\Workspace;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class WorkspaceSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createWorkspace(int $ownerUserId, array $overrides = []): int
    {
        return DB::table('workspaces')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'name' => 'Test Workspace',
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createMembership(int $workspaceId, int $userId, array $overrides = []): int
    {
        return DB::table('workspace_memberships')->insertGetId(array_merge([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createBusinessFor(int $customerId): Business
    {
        $workspaceId = $this->createWorkspace($customerId);

        $business = new Business($this->businessAttributes());
        $business->customer_id = $customerId;
        $business->workspace_id = $workspaceId;
        $business->save();

        return $business;
    }

    public function test_workspaces_uid_has_database_level_unique_constraint(): void
    {
        $ownerA = $this->createCustomer()->user_id;
        $ownerB = $this->createCustomer()->user_id;
        $uid = (string) Str::uuid();

        $this->createWorkspace($ownerA, ['uid' => $uid]);

        $this->expectException(QueryException::class);
        $this->createWorkspace($ownerB, ['uid' => $uid]);
    }

    public function test_workspace_memberships_enforces_unique_workspace_and_user(): void
    {
        $owner = $this->createCustomer()->user_id;
        $member = $this->createCustomer()->user_id;
        $workspaceId = $this->createWorkspace($owner);

        $this->createMembership($workspaceId, $member);

        $this->expectException(QueryException::class);
        $this->createMembership($workspaceId, $member);
    }

    public function test_workspace_membership_businesses_enforces_unique_membership_and_business(): void
    {
        $owner = $this->createCustomer();
        $workspaceId = $this->createWorkspace($owner->user_id);
        $membershipId = $this->createMembership($workspaceId, $owner->user_id);
        $business = $this->createBusinessFor($owner->user_id);

        DB::table('workspace_membership_businesses')->insert([
            'workspace_membership_id' => $membershipId,
            'business_id' => $business->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('workspace_membership_businesses')->insert([
            'workspace_membership_id' => $membershipId,
            'business_id' => $business->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_user_id_restricts_deletion_of_referenced_user(): void
    {
        $owner = $this->createCustomer();
        $this->createWorkspace($owner->user_id);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $owner->user_id)->delete();
    }

    public function test_workspace_memberships_workspace_id_restricts_deletion_of_workspace(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer();
        $workspaceId = $this->createWorkspace($owner->user_id);
        $this->createMembership($workspaceId, $member->user_id);

        $this->expectException(QueryException::class);
        DB::table('workspaces')->where('id', $workspaceId)->delete();
    }

    public function test_workspace_memberships_user_id_restricts_deletion_of_user(): void
    {
        $owner = $this->createCustomer();
        $member = $this->createCustomer();
        $workspaceId = $this->createWorkspace($owner->user_id);
        $this->createMembership($workspaceId, $member->user_id);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $member->user_id)->delete();
    }

    public function test_workspace_membership_businesses_restricts_deletion_of_membership(): void
    {
        $owner = $this->createCustomer();
        $workspaceId = $this->createWorkspace($owner->user_id);
        $membershipId = $this->createMembership($workspaceId, $owner->user_id);
        $business = $this->createBusinessFor($owner->user_id);

        DB::table('workspace_membership_businesses')->insert([
            'workspace_membership_id' => $membershipId,
            'business_id' => $business->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('workspace_memberships')->where('id', $membershipId)->delete();
    }

    public function test_workspace_membership_businesses_restricts_deletion_of_business(): void
    {
        $owner = $this->createCustomer();
        $workspaceId = $this->createWorkspace($owner->user_id);
        $membershipId = $this->createMembership($workspaceId, $owner->user_id);
        $business = $this->createBusinessFor($owner->user_id);

        DB::table('workspace_membership_businesses')->insert([
            'workspace_membership_id' => $membershipId,
            'business_id' => $business->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_business_access_scope_is_not_null_with_no_database_default(): void
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_memberships'
             AND COLUMN_NAME = 'business_access_scope'"
        );

        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertNull($column->COLUMN_DEFAULT);

        $owner = $this->createCustomer()->user_id;
        $workspaceId = $this->createWorkspace($owner);

        $this->expectException(QueryException::class);
        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $owner,
            'role' => 'staff',
            'business_access_scope' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // (rewritten for Slice 4B — migration 6 is now applied in every
    // migrate:fresh, so businesses.workspace_id is NOT NULL, not nullable.)
    public function test_businesses_workspace_id_column_is_not_null(): void
    {
        $this->assertTrue(Schema::hasColumn('businesses', 'workspace_id'));

        $owner = $this->createCustomer();
        $business = $this->createBusinessFor($owner->user_id);

        $this->assertNotNull($business->fresh()->workspace_id);

        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id'"
        );

        $this->assertSame('NO', $column->IS_NULLABLE);
    }

    // (rewritten for Slice 4B — the final businesses_workspace_id_foreign
    // FK now exists, referencing workspaces.id with RESTRICT.)
    public function test_businesses_workspace_id_has_the_final_foreign_key(): void
    {
        $constraint = DB::selectOne(
            "SELECT REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id' AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'"
        );

        $this->assertNotNull($constraint);
        $this->assertSame('workspaces', $constraint->REFERENCED_TABLE_NAME);
        $this->assertSame('id', $constraint->REFERENCED_COLUMN_NAME);

        $deleteRule = DB::selectOne(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'"
        );
        $this->assertSame('RESTRICT', $deleteRule->DELETE_RULE);
    }

    // (rewritten for Slice 4B — both final Workspace indexes now exist, in
    // the documented column order.)
    public function test_businesses_has_the_final_m1b_workspace_indexes(): void
    {
        $indexes = DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND INDEX_NAME IN ('businesses_workspace_id_index', 'businesses_workspace_id_status_index')"
        );
        $this->assertCount(2, $indexes);

        $compositeColumns = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'businesses')
            ->where('INDEX_NAME', 'businesses_workspace_id_status_index')
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();
        $this->assertSame(['workspace_id', 'status'], $compositeColumns);
    }
}
