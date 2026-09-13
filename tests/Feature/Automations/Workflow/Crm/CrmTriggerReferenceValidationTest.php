<?php

namespace Tests\Feature\Automations\Workflow\Crm;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowReferenceCatalogLoader;
use App\Library\Crm\CrmPipelineService;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\TestCase;

/**
 * Automations V2 §14.2 — "Opportunity moves stage" filters name CRM pipelines
 * and stages by id, and every one must belong to the workflow's Business.
 *
 * Checked the way contact references are: NodeTypeRegistry refuses a malformed
 * filter without touching the database; WorkflowCompiler refuses a foreign,
 * archived or mismatched one against the Business's catalog — the one catalog
 * statement the Builder already reads, now carrying the CRM half too.
 */
class CrmTriggerReferenceValidationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;

    private function pipeline(Business $business): CrmPipeline
    {
        return app(CrmPipelineService::class)->setUpStandardPipeline($business);
    }

    private function stage(CrmPipeline $pipeline, string $key): CrmPipelineStage
    {
        return CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('semantic_key', $key)->firstOrFail();
    }

    /** @return array<string, list<string>> */
    private function validate(Business $business, array $filters): array
    {
        $drafts = app(WorkflowDraftService::class);
        $version = $drafts->createWorkflowWithDraft($business, 'Stage filter ' . uniqid(), WorkflowTriggerType::OpportunityStageChanged)->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::OpportunityStageChanged);
        $definition['root']['config'] = array_merge($definition['root']['config'], $filters);
        $definition['root']['next'] = [['key' => 'end-1', 'type' => 'end', 'config' => []]];
        $version->definition = $definition;

        return app(WorkflowCompiler::class)->validate($version);
    }

    /** @return list<string> */
    private function messages(array $errors): array
    {
        return collect($errors)->flatten()->values()->all();
    }

    public function test_no_filter_and_this_businesss_own_filters_are_valid(): void
    {
        [, $business] = $this->entitledTenant();
        $pipeline = $this->pipeline($business);

        $this->assertSame([], $this->validate($business, []));
        $this->assertSame([], $this->validate($business, [
            'pipeline_id' => (int) $pipeline->id,
            'from_stage_id' => (int) $this->stage($pipeline, 'new_inquiry')->id,
            'to_stage_id' => (int) $this->stage($pipeline, 'qualified')->id,
        ]));
    }

    public function test_another_businesss_pipeline_and_stages_are_refused(): void
    {
        [, $business] = $this->entitledTenant();
        [, $other] = $this->entitledTenant();
        $this->pipeline($business);
        $theirs = $this->pipeline($other);

        $messages = $this->messages($this->validate($business, [
            'pipeline_id' => (int) $theirs->id,
            'from_stage_id' => (int) $this->stage($theirs, 'new_inquiry')->id,
            'to_stage_id' => (int) $this->stage($theirs, 'qualified')->id,
        ]));

        $this->assertContains('That pipeline does not belong to this business.', $messages);
        $this->assertContains('The stage it moves from does not belong to this business.', $messages);
        $this->assertContains('The stage it moves to does not belong to this business.', $messages);
    }

    public function test_a_foreign_id_reads_exactly_like_one_that_does_not_exist(): void
    {
        [, $business] = $this->entitledTenant();
        [, $other] = $this->entitledTenant();
        $theirs = $this->pipeline($other);

        $foreign = $this->validate($business, ['to_stage_id' => (int) $this->stage($theirs, 'qualified')->id]);
        $missing = $this->validate($business, ['to_stage_id' => 987654321]);

        $this->assertSame(array_values($foreign), array_values($missing), 'A refusal never confirms that another Business\'s stage exists.');
    }

    public function test_stages_must_sit_in_the_chosen_pipeline_and_in_one_pipeline(): void
    {
        [, $business] = $this->entitledTenant();
        $first = $this->pipeline($business);
        $second = app(CrmPipelineService::class)->createPipeline($business, 'Rentals');

        $this->assertContains(
            'The stage it moves to is not in the chosen pipeline.',
            $this->messages($this->validate($business, ['pipeline_id' => (int) $first->id, 'to_stage_id' => (int) $this->stage($second, 'qualified')->id])),
        );

        $this->assertContains(
            'A deal only moves between stages of one pipeline. Choose two stages of the same pipeline.',
            $this->messages($this->validate($business, [
                'from_stage_id' => (int) $this->stage($first, 'new_inquiry')->id,
                'to_stage_id' => (int) $this->stage($second, 'qualified')->id,
            ])),
        );
    }

    public function test_an_archived_stage_is_named_as_archived_not_as_missing(): void
    {
        [, $business] = $this->entitledTenant();
        $pipeline = $this->pipeline($business);
        $qualified = $this->stage($pipeline, 'qualified');
        app(CrmPipelineService::class)->archiveStage($qualified);

        $this->assertContains(
            'The stage it moves to is archived. Choose an active stage, or any stage.',
            $this->messages($this->validate($business, ['to_stage_id' => (int) $qualified->id])),
        );
    }

    public function test_a_malformed_filter_is_refused_without_the_database(): void
    {
        $registry = new NodeTypeRegistry();
        $base = ['trigger_type' => 'opportunity_stage_changed', 'enrollment_policy' => 'once_per_occurrence', 'enrollment_policy_source' => 'default', 'failure_policy' => 'halt'];

        DB::enableQueryLog();
        $sameStage = $registry->validateConfig(WorkflowNodeType::Trigger, $base + ['from_stage_id' => 5, 'to_stage_id' => 5]);
        $notAnId = $registry->validateConfig(WorkflowNodeType::Trigger, $base + ['pipeline_id' => 'sales']);
        DB::disableQueryLog();

        $this->assertSame([], DB::getQueryLog());
        $this->assertContains('A deal cannot move from a stage to the same stage. Choose two different stages.', $sameStage);
        $this->assertContains('Choose a valid pipeline, or leave it as any pipeline.', $notAnId);
        $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $base + ['pipeline_id' => null, 'from_stage_id' => null, 'to_stage_id' => null]));
    }

    public function test_the_catalog_carries_only_this_businesss_crm_rows_in_one_statement(): void
    {
        [, $business] = $this->entitledTenant();
        [, $other] = $this->entitledTenant();
        $mine = $this->pipeline($business);
        $this->pipeline($other);
        $this->contactGroup($business, 'Clients');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $catalog = app(WorkflowReferenceCatalogLoader::class)->forBusiness($business);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Contacts and CRM references are one read.');
        $this->assertStringContainsString('`crm_pipelines`.`business_id` = ?', $queries[0]);
        $this->assertStringContainsString('`contact_groups`.`business_id` = ?', $queries[0]);

        $this->assertSame([(int) $mine->id], array_column($catalog->pipelines(), 'id'));
        $this->assertSame(
            CrmPipelineStage::query()->where('pipeline_id', $mine->id)->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all(),
            array_column($catalog->stages(), 'id'),
            'Only this Business\'s stages, in board order.',
        );
        $this->assertSame(['Clients'], array_column($catalog->groups(), 'name'), 'The contact half is unchanged.');
    }

    /** The version a draft pins keeps the filter as ids, never as the stage names. */
    public function test_publishing_pins_the_filters_as_ids(): void
    {
        [, $business] = $this->entitledTenant();
        $pipeline = $this->pipeline($business);
        $qualified = $this->stage($pipeline, 'qualified');

        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Pinned', WorkflowTriggerType::OpportunityStageChanged);
        $definition = $drafts->starterDefinition(WorkflowTriggerType::OpportunityStageChanged);
        $definition['root']['config']['to_stage_id'] = (int) $qualified->id;
        $definition['root']['next'] = [['key' => 'end-1', 'type' => 'end', 'config' => []]];
        $draft = $workflow->draftVersion();
        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);

        $version = app(\App\Library\Automation\Workflow\WorkflowPublisher::class)->publish($workflow->fresh());

        $this->assertSame(WorkflowTriggerType::OpportunityStageChanged, AutomationWorkflowVersion::query()->find($version->id)->trigger_type);
        $config = json_decode((string) DB::table('automation_workflow_nodes')->where('version_id', $version->id)->where('node_type', 'trigger')->value('config'), true);
        $this->assertSame((int) $qualified->id, (int) $config['to_stage_id']);
        $this->assertStringNotContainsString($qualified->name, json_encode($config), 'A stage is referenced by id, never by its renameable name.');
    }
}
