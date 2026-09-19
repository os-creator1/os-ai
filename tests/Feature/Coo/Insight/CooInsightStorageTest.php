<?php

namespace Tests\Feature\Coo\Insight;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\CooInsight;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * AI-3 — contract §9.1 storage: the columns, the identity that refuses paying
 * twice, soft invalidation, and the cascades §15.9 names.
 */
class CooInsightStorageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCooInsightFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    public function test_the_table_carries_every_contract_column(): void
    {
        foreach ([
            'id', 'uid', 'business_id', 'workspace_id', 'kind', 'subject_type', 'subject_id', 'period_key',
            'signal_fingerprint', 'facts_snapshot', 'output', 'prompt_version', 'policy_version', 'model_route',
            'provider_model', 'ai_usage_ledger_entry_id', 'generated_at', 'expires_at', 'invalidated_at',
            'invalidation_reason', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('coo_insights', $column), "coo_insights.{$column}");
        }

        foreach (Schema::getColumnListing('coo_insights') as $column) {
            foreach (['prompt_text', 'response', 'message', 'contact', 'phone', 'email'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $column, 'No column exists that could hold a prompt, a reply or personal data.');
            }
        }
    }

    public function test_the_identity_refuses_a_second_row_for_identical_facts(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $first = $this->cachedInsight($business);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->cachedInsight($business, ['signal_fingerprint' => $first->signal_fingerprint]);
    }

    public function test_the_same_facts_under_a_new_prompt_version_are_a_new_identity(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $first = $this->cachedInsight($business);

        $second = $this->cachedInsight($business, ['signal_fingerprint' => $first->signal_fingerprint, 'prompt_version' => $first->prompt_version + 1]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->uid, $second->uid);
    }

    public function test_deleting_a_business_takes_its_insights_and_a_ledger_row_only_unlinks(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $fresh = $business->fresh();
        app(\App\Library\Coo\Insight\CooInsightGenerator::class)->generate($fresh, \App\Enums\Coo\CooInsightTrigger::MultiSignalChange, $this->thisMonth($business), $this->backgroundEnvelope($fresh));

        $insight = CooInsight::query()->sole();
        $this->assertNotNull($insight->ai_usage_ledger_entry_id);

        DB::table('ai_usage_ledger')->where('id', $insight->ai_usage_ledger_entry_id)->delete();
        $this->assertNull($insight->fresh()->ai_usage_ledger_entry_id, 'Cost provenance is nullable: the insight survives the ledger row.');

        // Other product tables deliberately RESTRICT deleting a Business, so the
        // cascade is proved on the constraint itself rather than by a delete.
        $rules = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name, DELETE_RULE AS rule, REFERENCED_TABLE_NAME AS parent FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['coo_insights'],
        ))->keyBy('parent');

        $this->assertSame('CASCADE', $rules['businesses']->rule, 'A Business\'s insights go with it (§15.9).');
        $this->assertSame('CASCADE', $rules['workspaces']->rule);
        $this->assertSame('SET NULL', $rules['ai_usage_ledger']->rule);
    }
}
