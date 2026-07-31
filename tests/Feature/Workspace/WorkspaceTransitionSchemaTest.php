<?php

namespace Tests\Feature\Workspace;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A tests #9-14: the exact workspace_transitions
 * schema shape (columns, nullability, FKs, indexes) verified through
 * information_schema against the primary shared testing database — the
 * migration itself is purely additive and safe to run in the normal suite.
 */
class WorkspaceTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function columns(): Collection
    {
        return DB::table('information_schema.COLUMNS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_transitions')
            ->orderBy('ORDINAL_POSITION')
            ->get(['COLUMN_NAME', 'IS_NULLABLE'])
            ->keyBy('COLUMN_NAME');
    }

    // 9. exact columns and nullability.
    public function test_exact_columns_and_nullability(): void
    {
        $columns = $this->columns();

        $expected = [
            'id' => 'NO',
            'workspace_id' => 'NO',
            'transition_type' => 'NO',
            'actor_user_id' => 'NO',
            'from_owner_user_id' => 'YES',
            'to_owner_user_id' => 'YES',
            'previous_owner_disposition' => 'YES',
            'business_id' => 'YES',
            'from_workspace_id' => 'YES',
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

    // 10. actor_user_id/from_owner_user_id/to_owner_user_id have no foreign keys.
    public function test_audit_identity_columns_have_no_foreign_keys(): void
    {
        foreach (['actor_user_id', 'from_owner_user_id', 'to_owner_user_id'] as $column) {
            $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_transitions')
                ->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->exists();

            $this->assertFalse($exists, "Column [{$column}] must not have a foreign key.");
        }
    }

    // 11. exactly three foreign keys exist, all RESTRICT.
    public function test_exactly_three_foreign_keys_with_restrict(): void
    {
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_transitions')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get(['CONSTRAINT_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'])
            ->keyBy('CONSTRAINT_NAME');

        $this->assertCount(3, $foreignKeys);

        $expected = [
            'workspace_transitions_workspace_id_foreign' => ['workspace_id', 'workspaces'],
            'workspace_transitions_business_id_foreign' => ['business_id', 'businesses'],
            'workspace_transitions_from_workspace_id_foreign' => ['from_workspace_id', 'workspaces'],
        ];

        foreach ($expected as $constraintName => [$column, $referencedTable]) {
            $this->assertTrue($foreignKeys->has($constraintName), "Missing FK [{$constraintName}].");
            $this->assertSame($column, $foreignKeys[$constraintName]->COLUMN_NAME);
            $this->assertSame($referencedTable, $foreignKeys[$constraintName]->REFERENCED_TABLE_NAME);
            $this->assertSame('id', $foreignKeys[$constraintName]->REFERENCED_COLUMN_NAME);

            $deleteRule = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_transitions')
                ->where('CONSTRAINT_NAME', $constraintName)
                ->value('DELETE_RULE');

            $this->assertSame('RESTRICT', $deleteRule, "FK [{$constraintName}] must be RESTRICT.");
        }
    }

    // 12/13. exactly the two explicit named indexes exist, composite in workspace_id, created_at order.
    public function test_exact_two_explicit_indexes_with_composite_order(): void
    {
        $this->assertTrue($this->indexExists('workspace_transitions_workspace_id_created_at_index'));
        $this->assertTrue($this->indexExists('workspace_transitions_business_id_index'));

        $compositeColumns = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_transitions')
            ->where('INDEX_NAME', 'workspace_transitions_workspace_id_created_at_index')
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();

        $this->assertSame(['workspace_id', 'created_at'], $compositeColumns);

        $businessIdColumns = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_transitions')
            ->where('INDEX_NAME', 'workspace_transitions_business_id_index')
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();

        $this->assertSame(['business_id'], $businessIdColumns);
    }

    // 14. no index exists whose only column is workspace_id (the composite already covers the FK requirement).
    public function test_no_redundant_workspace_id_only_index_exists(): void
    {
        $indexNames = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_transitions')
            ->distinct()
            ->pluck('INDEX_NAME');

        foreach ($indexNames as $indexName) {
            $columns = DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'workspace_transitions')
                ->where('INDEX_NAME', $indexName)
                ->pluck('COLUMN_NAME')
                ->all();

            if ($columns === ['workspace_id']) {
                $this->fail("Redundant workspace_id-only index found: [{$indexName}].");
            }
        }

        $this->assertTrue(true);
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'workspace_transitions')
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
}
