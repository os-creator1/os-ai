<?php

namespace Tests\Feature\Crm;

use App\Models\CrmOpportunity;
use App\Models\CrmPipelineStage;
use App\Models\Opportunity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The CRM sales domain is its own schema: `crm_*` tables, `App\Models\Crm*`
 * models, and nothing added to or borrowed from the AI COO recommendation
 * engine's `opportunities` tables.
 */
class CrmSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_the_crm_domain_is_distinct_from_the_ai_coo_opportunities(): void
    {
        foreach (['crm_pipelines', 'crm_pipeline_stages', 'crm_opportunities', 'crm_opportunity_history'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertSame('crm_opportunities', (new CrmOpportunity())->getTable());
        $this->assertSame('opportunities', (new Opportunity())->getTable());

        foreach (['pipeline_id', 'stage_id', 'contact_status', 'value_minor'] as $column) {
            $this->assertFalse(Schema::hasColumn('opportunities', $column), "The AI COO table must not gain {$column}.");
        }

        $crmForeignKeys = collect(DB::select(
            'select referenced_table_name as target from information_schema.key_column_usage
             where table_schema = database() and table_name like ? and referenced_table_name is not null',
            ['crm\\_%'],
        ))->pluck('target')->unique();

        $this->assertEmpty($crmForeignKeys->filter(fn ($target) => str_starts_with((string) $target, 'opportunit'))->all(), 'No CRM table references the AI COO tables.');
    }

    public function test_a_semantic_key_is_unique_within_a_pipeline_but_not_across_pipelines(): void
    {
        [, $business] = $this->crmTenant();
        $first = $this->standardPipeline($business);
        $second = app(\App\Library\Crm\CrmPipelineService::class)->createPipeline($business, 'Weddings');

        $this->assertSame(2, CrmPipelineStage::query()->where('semantic_key', 'new_inquiry')->count());

        $this->expectException(QueryException::class);
        CrmPipelineStage::create(['business_id' => $business->id, 'pipeline_id' => $first->id, 'name' => 'Duplicate', 'semantic_key' => 'new_inquiry', 'position' => 9]);

        $this->assertNotNull($second);
    }

    public function test_deleting_a_contact_never_deletes_or_blocks_on_a_deal(): void
    {
        $rules = collect(DB::select(
            'select table_name as t, constraint_name as c, delete_rule as r from information_schema.referential_constraints
             where constraint_schema = database() and table_name in (?, ?)',
            ['crm_opportunities', 'crm_opportunity_history'],
        ))->mapWithKeys(fn ($row) => [$row->c => $row->r]);

        $this->assertSame('SET NULL', $rules['crm_opportunities_contact_id_foreign']);
        $this->assertSame('SET NULL', $rules['crm_opportunity_history_from_stage_id_foreign']);
        $this->assertSame('SET NULL', $rules['crm_opportunity_history_to_stage_id_foreign']);
        $this->assertSame('CASCADE', $rules['crm_opportunities_business_id_foreign']);
    }
}
