<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Events\Crm\CrmOpportunityCreated;
use App\Events\Crm\CrmOpportunityLost;
use App\Events\Crm\CrmOpportunityStageChanged;
use App\Events\Crm\CrmOpportunityWon;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2 — CRM opportunity triggers through the real Builder routes.
 *
 * The page offers the four triggers in customer words and hands the inspector
 * only this Business's pipelines and stages; a draft naming another Business's
 * stage autosaves with the refusal and cannot publish; and Test workflow on a
 * CRM-triggered draft changes nothing in the CRM, emits no CRM event and
 * enrolls nobody.
 */
class WorkflowCrmTriggerBuilderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    private function pipeline(Business $business): CrmPipeline
    {
        return app(CrmPipelineService::class)->setUpStandardPipeline($business);
    }

    /** @return array<string, mixed> */
    private function builderData(string $html): array
    {
        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);
        $this->assertNotEmpty($match, 'The builder bootstrap blob must be rendered.');

        return json_decode(html_entity_decode($match[1]), true);
    }

    private function crmDraftWorkflow(array $tenant, array $triggerConfig): AutomationWorkflow
    {
        $url = $this->routeUrl('store', $tenant['workspace'], $tenant['business']);
        $created = $this->callJson('POST', $url, ['name' => 'Deal follow-up', 'trigger_type' => 'opportunity_stage_changed'])->assertCreated();

        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $tenant['workspace'], $tenant['business'], $workflow))->assertOk()->json();

        $definition = $draft['definition'];
        $definition['root']['config'] = array_merge($definition['root']['config'], $triggerConfig);
        $definition['root']['next'] = [$this->endStep()];

        $this->callJson('PUT', $this->routeUrl('draft.autosave', $tenant['workspace'], $tenant['business'], $workflow), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        return $workflow;
    }

    public function test_the_builder_offers_the_four_crm_triggers_in_customer_words(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);

        $html = $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))->assertOk()->getContent();

        preg_match('#<template id="wf-node-form-trigger">(.*?)</template>#s', $html, $trigger);
        $this->assertNotEmpty($trigger);

        foreach ([
            'opportunity_created' => 'Opportunity created',
            'opportunity_stage_changed' => 'Opportunity moves stage',
            'opportunity_won' => 'Opportunity marked won',
            'opportunity_lost' => 'Opportunity marked lost',
        ] as $value => $words) {
            $this->assertStringContainsString('name="wf-trigger-type" value="' . $value . '"', $trigger[1]);
            $this->assertStringContainsString($words, $trigger[1]);
        }

        // The filters "Opportunity moves stage" offers.
        foreach (['wf-crm-pipeline-select', 'wf-crm-from-stage-select', 'wf-crm-to-stage-select'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $trigger[1]);
        }

        $this->assertDoesNotMatchRegularExpression('/CrmOpportunity(Created|StageChanged|Won|Lost)/', strip_tags($html), 'No internal event class is ever shown.');
    }

    public function test_the_stage_pickers_carry_only_this_businesss_pipelines_and_stages(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $mine = $this->pipeline($t['business']);

        $other = $this->tenantWithWorkflow();
        $theirs = $this->pipeline($other['business']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))->assertOk()->getContent();
        $crmReads = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'crm_pipeline'));
        DB::disableQueryLog();

        $catalogs = $this->builderData($html)['catalogs'];

        $this->assertSame([(int) $mine->id], array_column($catalogs['crmPipelines'], 'id'));
        $this->assertEqualsCanonicalizing(
            CrmPipelineStage::query()->where('pipeline_id', $mine->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            array_column($catalogs['crmStages'], 'id'),
        );
        $this->assertNotContains((int) $theirs->id, array_column($catalogs['crmPipelines'], 'id'));
        $this->assertSame(['id', 'pipeline_id', 'name', 'archived'], array_keys($catalogs['crmStages'][0]), 'Ids and display names only.');

        $this->assertCount(1, $crmReads, 'The pickers cost no query of their own: one catalog read, shared with the contact pickers.');
    }

    public function test_a_draft_naming_another_businesss_stage_saves_with_the_refusal_and_cannot_publish(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $this->pipeline($t['business']);

        $other = $this->tenantWithWorkflow();
        $foreignStage = CrmPipelineStage::query()->where('pipeline_id', $this->pipeline($other['business'])->id)->where('semantic_key', 'qualified')->firstOrFail();

        $workflow = $this->crmDraftWorkflow($t, ['to_stage_id' => (int) $foreignStage->id]);

        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $t['workspace'], $t['business'], $workflow))->assertOk()->json();
        $this->assertContains('The stage it moves to does not belong to this business.', collect($draft['errors'])->flatten()->all());

        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $workflow))
            ->assertStatus(422)
            ->assertJsonFragment(['The stage it moves to does not belong to this business.']);

        $this->assertNull($workflow->fresh()->published_version_id);
    }

    public function test_a_valid_stage_filter_publishes(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $pipeline = $this->pipeline($t['business']);
        $qualified = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('semantic_key', 'qualified')->firstOrFail();

        $workflow = $this->crmDraftWorkflow($t, ['pipeline_id' => (int) $pipeline->id, 'to_stage_id' => (int) $qualified->id]);

        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $workflow))
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    /** Test workflow on a CRM trigger: a path to look at, and nothing else happens. */
    public function test_simulating_a_crm_triggered_draft_has_no_side_effects(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $pipeline = $this->pipeline($t['business']);

        // A real deal exists, so "nothing changes" is a claim about real rows.
        app(CrmOpportunityService::class)->create($t['business'], $pipeline, $t['contact'], 'Existing deal');

        $other = $this->tenantWithWorkflow();
        $foreignStage = CrmPipelineStage::query()->where('pipeline_id', $this->pipeline($other['business'])->id)->firstOrFail();
        $workflow = $this->crmDraftWorkflow($t, ['from_stage_id' => (int) $foreignStage->id]);

        $counts = fn (): array => [
            'crm_opportunities' => DB::table('crm_opportunities')->count(),
            'crm_opportunity_history' => DB::table('crm_opportunity_history')->count(),
            'crm_pipeline_stages' => DB::table('crm_pipeline_stages')->count(),
            'automation_enrollments' => DB::table('automation_enrollments')->count(),
        ];
        $before = $counts();

        Event::fake([CrmOpportunityCreated::class, CrmOpportunityStageChanged::class, CrmOpportunityWon::class, CrmOpportunityLost::class]);

        $body = $this->callJson('POST', $this->routeUrl('simulate', $t['workspace'], $t['business'], $workflow), ['contact_uid' => $t['contact']->uid])
            ->assertOk()
            ->json();

        $this->assertSame('trigger', $body['path'][0]['type']);
        $this->assertContains('The stage it moves from does not belong to this business.', collect($body['validation'])->flatten()->all(), 'The trigger configuration is validated and shown.');

        $this->assertSame($before, $counts(), 'No deal, history, stage or enrollment is written.');
        Event::assertNotDispatched(CrmOpportunityCreated::class);
        Event::assertNotDispatched(CrmOpportunityStageChanged::class);
        Event::assertNotDispatched(CrmOpportunityWon::class);
        Event::assertNotDispatched(CrmOpportunityLost::class);
    }
}
