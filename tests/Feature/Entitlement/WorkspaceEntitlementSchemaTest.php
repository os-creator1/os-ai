<?php

namespace Tests\Feature\Entitlement;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-004 Milestone 1 Contract §13 — exact schema shape (columns,
 * nullability, unique constraints, restrictOnDelete FKs) for
 * workspace_plan_catalog, workspace_plan_features, workspace_plan_assignments,
 * workspace_entitlement_overrides, and business_feature_toggles. The audit
 * table (workspace_entitlement_transitions) has its own dedicated file,
 * mirroring RFC-003's WorkspaceSchemaTest/WorkspaceTransitionSchemaTest
 * split.
 */
class WorkspaceEntitlementSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function coreCatalogId(): int
    {
        return (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
    }

    private function insertCatalog(array $overrides = []): int
    {
        return DB::table('workspace_plan_catalog')->insertGetId(array_merge([
            'tier' => 'test_tier_' . uniqid(),
            'display_name' => 'Test Tier',
            'price' => null,
            'currency_id' => null,
            'billing_cycle' => 'monthly',
            'business_slot_included' => 3,
            'business_slot_max' => 5,
            'unlimited_business_slots' => false,
            'additional_business_slot_price_ratio' => 0.5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertAssignment(int $workspaceId, array $overrides = []): int
    {
        return DB::table('workspace_plan_assignments')->insertGetId(array_merge([
            'workspace_id' => $workspaceId,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => 'active',
            'is_complimentary' => true,
            'additional_business_slots' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // --- workspace_plan_catalog ---

    public function test_catalog_seeded_exactly_three_rows(): void
    {
        $this->assertSame(3, DB::table('workspace_plan_catalog')->count());
        $this->assertEqualsCanonicalizing(
            ['core', 'growth', 'agency'],
            DB::table('workspace_plan_catalog')->pluck('tier')->all()
        );
    }

    public function test_catalog_tier_has_a_database_level_unique_constraint(): void
    {
        $tier = 'duplicate_tier_' . uniqid();
        $this->insertCatalog(['tier' => $tier]);

        $this->expectException(QueryException::class);
        $this->insertCatalog(['tier' => $tier]);
    }

    public function test_catalog_price_and_currency_id_are_nullable(): void
    {
        $id = $this->insertCatalog();
        $row = DB::table('workspace_plan_catalog')->where('id', $id)->first();

        $this->assertNull($row->price);
        $this->assertNull($row->currency_id);
    }

    public function test_catalog_currency_id_restricts_deletion_of_referenced_currency(): void
    {
        $currencyId = DB::table('currencies')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'US Dollar',
            'code' => 'USD',
            'format' => '$',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertCatalog(['currency_id' => $currencyId]);

        $this->expectException(QueryException::class);
        DB::table('currencies')->where('id', $currencyId)->delete();
    }

    // --- workspace_plan_features ---

    public function test_plan_features_enforces_unique_catalog_and_feature_key(): void
    {
        $catalogId = $this->insertCatalog();
        DB::table('workspace_plan_features')->insert([
            'workspace_plan_catalog_id' => $catalogId,
            'feature_key' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('workspace_plan_features')->insert([
            'workspace_plan_catalog_id' => $catalogId,
            'feature_key' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_plan_features_restricts_deletion_of_referenced_catalog_row(): void
    {
        $catalogId = $this->insertCatalog();
        DB::table('workspace_plan_features')->insert([
            'workspace_plan_catalog_id' => $catalogId,
            'feature_key' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('workspace_plan_catalog')->where('id', $catalogId)->delete();
    }

    public function test_seeded_packaging_matrix_is_nine_twelve_fifteen(): void
    {
        $catalogIds = DB::table('workspace_plan_catalog')->pluck('id', 'tier');

        $this->assertSame(9, DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalogIds['core'])->count());
        $this->assertSame(12, DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalogIds['growth'])->count());
        $this->assertSame(15, DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalogIds['agency'])->count());
    }

    public function test_planned_feature_prospect_outreach_still_receives_an_agency_packaging_row(): void
    {
        $agencyId = DB::table('workspace_plan_catalog')->where('tier', 'agency')->value('id');

        $this->assertTrue(
            DB::table('workspace_plan_features')
                ->where('workspace_plan_catalog_id', $agencyId)
                ->where('feature_key', 'prospect_outreach')
                ->exists()
        );
    }

    // --- workspace_plan_assignments ---

    public function test_assignments_enforces_unique_workspace_id(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->insertAssignment($workspace->id);

        $this->expectException(QueryException::class);
        $this->insertAssignment($workspace->id);
    }

    public function test_assignments_status_is_not_null_with_no_database_default(): void
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_plan_assignments'
             AND COLUMN_NAME = 'status'"
        );

        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertNull($column->COLUMN_DEFAULT);

        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->expectException(QueryException::class);
        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_assignments_restricts_deletion_of_referenced_workspace(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->insertAssignment($workspace->id);

        $this->expectException(QueryException::class);
        DB::table('workspaces')->where('id', $workspace->id)->delete();
    }

    public function test_assignments_restricts_deletion_of_referenced_catalog_row(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $catalogId = $this->coreCatalogId();
        $this->insertAssignment($workspace->id, ['workspace_plan_catalog_id' => $catalogId]);

        $this->expectException(QueryException::class);
        DB::table('workspace_plan_catalog')->where('id', $catalogId)->delete();
    }

    public function test_assignments_is_complimentary_and_additional_business_slots_defaults(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $id = DB::table('workspace_plan_assignments')->insertGetId([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('workspace_plan_assignments')->where('id', $id)->first();
        $this->assertSame(0, (int) $row->is_complimentary);
        $this->assertSame(0, (int) $row->additional_business_slots);
    }

    // --- workspace_entitlement_overrides ---

    public function test_overrides_enforces_unique_workspace_and_feature_key(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        DB::table('workspace_entitlement_overrides')->insert([
            'workspace_id' => $workspace->id,
            'feature_key' => 'crm',
            'state' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('workspace_entitlement_overrides')->insert([
            'workspace_id' => $workspace->id,
            'feature_key' => 'crm',
            'state' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_overrides_restricts_deletion_of_referenced_workspace(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        DB::table('workspace_entitlement_overrides')->insert([
            'workspace_id' => $workspace->id,
            'feature_key' => 'crm',
            'state' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('workspaces')->where('id', $workspace->id)->delete();
    }

    // --- business_feature_toggles ---

    public function test_toggles_enforces_unique_business_and_feature_key(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_feature_toggles')->insert([
            'business_id' => $business->id,
            'feature_key' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_feature_toggles')->insert([
            'business_id' => $business->id,
            'feature_key' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_toggles_restricts_deletion_of_referenced_business(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_feature_toggles')->insert([
            'business_id' => $business->id,
            'feature_key' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }
}
