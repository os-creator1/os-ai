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
        return Business::create(array_merge(
            $this->businessAttributes(),
            ['customer_id' => $customerId]
        ));
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

    public function test_businesses_workspace_id_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('businesses', 'workspace_id'));

        $owner = $this->createCustomer();
        $business = $this->createBusinessFor($owner->user_id);

        $this->assertNull($business->fresh()->workspace_id);

        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id'"
        );

        $this->assertSame('YES', $column->IS_NULLABLE);
    }

    public function test_businesses_workspace_id_has_no_foreign_key_yet(): void
    {
        $constraints = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        $this->assertCount(0, $constraints);
    }

    public function test_businesses_has_no_m1b_workspace_indexes_yet(): void
    {
        $indexes = DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id'"
        );

        $this->assertCount(0, $indexes);
    }
}
