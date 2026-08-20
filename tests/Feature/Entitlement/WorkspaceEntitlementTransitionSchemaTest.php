<?php

namespace Tests\Feature\Entitlement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-004 Milestone 1 Contract §13 — the exact workspace_entitlement_transitions
 * schema shape (columns, nullability, FKs, indexes) verified through
 * information_schema, mirroring RFC-003's WorkspaceTransitionSchemaTest
 * exactly.
 */
class WorkspaceEntitlementTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function columns(): Collection
    {
        return DB::table('information_schema.COLUMNS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_entitlement_transitions')
            ->orderBy('ORDINAL_POSITION')
            ->get(['COLUMN_NAME', 'IS_NULLABLE'])
            ->keyBy('COLUMN_NAME');
    }

    public function test_exact_columns_and_nullability(): void
    {
        $columns = $this->columns();

        $expected = [
            'id' => 'NO',
            'workspace_id' => 'NO',
            'transition_type' => 'NO',
            'actor_user_id' => 'YES',
            'requesting_customer_user_id' => 'YES',
            'from_plan_catalog_id' => 'YES',
            'to_plan_catalog_id' => 'YES',
            'feature_key' => 'YES',
            'from_override_state' => 'YES',
            'to_override_state' => 'YES',
            'from_additional_business_slots' => 'YES',
            'to_additional_business_slots' => 'YES',
            'from_status' => 'YES',
            'to_status' => 'YES',
            'reason' => 'YES',
            'payment_idempotency_key' => 'YES',
            'created_at' => 'NO',
        ];

        $this->assertSame(array_keys($expected), $columns->keys()->all());

        foreach ($expected as $column => $nullable) {
            $this->assertSame($nullable, $columns[$column]->IS_NULLABLE, "Column [{$column}] nullability mismatch.");
        }
    }

    public function test_no_updated_at_column_exists(): void
    {
        $this->assertArrayNotHasKey('updated_at', $this->columns()->all());
    }

    public function test_audit_identity_columns_have_no_foreign_keys(): void
    {
        foreach (['actor_user_id'] as $column) {
            $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_entitlement_transitions')
                ->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->exists();

            $this->assertFalse($exists, "Column [{$column}] must not have a foreign key.");
        }
    }

    public function test_exactly_three_foreign_keys_all_restrict(): void
    {
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_entitlement_transitions')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get(['CONSTRAINT_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'])
            ->keyBy('COLUMN_NAME');

        $this->assertCount(3, $foreignKeys);

        $expected = [
            'workspace_id' => 'workspaces',
            'from_plan_catalog_id' => 'workspace_plan_catalog',
            'to_plan_catalog_id' => 'workspace_plan_catalog',
        ];

        foreach ($expected as $column => $referencedTable) {
            $this->assertTrue($foreignKeys->has($column), "Missing FK on column [{$column}].");
            $this->assertSame($referencedTable, $foreignKeys[$column]->REFERENCED_TABLE_NAME);
            $this->assertSame('id', $foreignKeys[$column]->REFERENCED_COLUMN_NAME);

            $constraintName = $foreignKeys[$column]->CONSTRAINT_NAME;
            $deleteRule = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_entitlement_transitions')
                ->where('CONSTRAINT_NAME', $constraintName)
                ->value('DELETE_RULE');

            $this->assertSame('RESTRICT', $deleteRule, "FK on [{$column}] must be RESTRICT.");
        }
    }

    public function test_composite_workspace_id_created_at_index_exists_in_order(): void
    {
        $indexNames = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_entitlement_transitions')
            ->distinct()
            ->pluck('INDEX_NAME');

        $compositeIndex = null;

        foreach ($indexNames as $indexName) {
            $columns = DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_entitlement_transitions')
                ->where('INDEX_NAME', $indexName)
                ->orderBy('SEQ_IN_INDEX')
                ->pluck('COLUMN_NAME')
                ->all();

            if ($columns === ['workspace_id', 'created_at']) {
                $compositeIndex = $indexName;
            }
        }

        $this->assertNotNull($compositeIndex, 'Expected a composite (workspace_id, created_at) index.');
    }

    public function test_no_redundant_workspace_id_only_index_exists(): void
    {
        $indexNames = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_entitlement_transitions')
            ->distinct()
            ->pluck('INDEX_NAME');

        foreach ($indexNames as $indexName) {
            $columns = DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_entitlement_transitions')
                ->where('INDEX_NAME', $indexName)
                ->pluck('COLUMN_NAME')
                ->all();

            if ($columns === ['workspace_id']) {
                $this->fail("Redundant workspace_id-only index found: [{$indexName}].");
            }
        }

        $this->assertTrue(true);
    }
}
