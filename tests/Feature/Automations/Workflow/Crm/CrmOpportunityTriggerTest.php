<?php

namespace Tests\Feature\Automations\Workflow\Crm;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Crm\CrmOpportunityCreated;
use App\Events\Crm\CrmOpportunityEvent;
use App\Events\Crm\CrmOpportunityLost;
use App\Events\Crm\CrmOpportunityStageChanged;
use App\Events\Crm\CrmOpportunityWon;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\Triggers\CrmOpportunityTriggerContext;
use App\Library\Automation\Workflow\Triggers\CrmOpportunityTriggerSource;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Crm\CrmOpportunityService;
use App\Listeners\Automation\Workflow\EnrollFromCrmOpportunityEvent;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityHistory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Automations V2 — CRM sales opportunity events start workflows.
 *
 * Every enrollment here is produced the way production produces it: the CRM
 * service changes a deal and emits its after-commit event, the queued listener
 * (sync in tests) hands it to the trigger source, and EnrollmentService makes
 * the one row. Nothing calls the source with invented facts except the replay
 * and forgery cases, which build an event from a real history row.
 *
 * The domain is CRM sales deals (crm_*). The AI COO / Advisor recommendation
 * `opportunities` are never touched.
 */
class CrmOpportunityTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use BuildsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        // The journey itself is the runtime's concern; here only the enrollment.
        Bus::fake([AdvanceWorkflowEnrollment::class]);
    }

    /** A published workflow on a CRM trigger, with optional stage-change filters. */
    private function crmWorkflow(Business $business, WorkflowTriggerType $type, array $filters = []): AutomationWorkflow
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'CRM ' . $type->value . ' ' . uniqid(), $type);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition($type);
        $definition['root']['config'] = array_merge($definition['root']['config'], $filters);
        $definition['root']['next'] = [$this->endStep()];

        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        return $workflow->fresh();
    }

    private function enrollmentsFor(AutomationWorkflow $workflow): int
    {
        return AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count();
    }

    private function source(WorkflowTriggerType $type): CrmOpportunityTriggerSource
    {
        return app(TriggerSourceRegistry::class)->for($type);
    }

    private function latestHistory(CrmOpportunity $deal): CrmOpportunityHistory
    {
        return CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->orderByDesc('id')->firstOrFail();
    }

    /** Close every journey so only the occurrence key can refuse a second enrollment. */
    private function finishJourneys(): void
    {
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);
    }

    // =================================================================
    // Each trigger enrolls once, with its facts
    // =================================================================

    public function test_opportunity_created_enrolls_the_deals_contact_once(): void
    {
        [, $business] = $this->crmTenant();
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityCreated);
        $pipeline = $this->standardPipeline($business);

        $deal = $this->deal($business, $pipeline);

        $this->assertSame(1, $this->enrollmentsFor($workflow));
        $enrollment = AutomationEnrollment::query()->sole();
        $history = $this->latestHistory($deal);

        $this->assertSame(WorkflowTriggerType::OpportunityCreated, $enrollment->trigger_type);
        $this->assertSame((int) $deal->contact_id, (int) $enrollment->contact_id);
        $this->assertSame('crm_opportunity_history:' . $history->id, $enrollment->trigger_occurrence_key, 'The CRM\'s own occurrence key.');

        $context = $this->source(WorkflowTriggerType::OpportunityCreated)->contextForEnrollment($enrollment);
        $this->assertSame([
            'trigger_type' => 'opportunity_created',
            'business_id' => (int) $business->id,
            'history_id' => (int) $history->id,
            'opportunity_id' => (int) $deal->id,
            'contact_id' => (int) $deal->contact_id,
            'pipeline_id' => (int) $pipeline->id,
            'stage_id' => (int) $deal->stage_id,
            'from_stage_id' => null,
            'to_stage_id' => null,
            'outcome' => null,
        ], $context->toArray());

        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);
    }

    public function test_opportunity_moves_stage_enrolls_once_with_the_from_and_to_stages(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $newInquiry = $this->stageKeyed($pipeline, 'new_inquiry');
        $qualified = $this->stageKeyed($pipeline, 'qualified');

        $deal = $this->deal($business, $pipeline);
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityStageChanged);

        app(CrmOpportunityService::class)->moveToStage($deal, $qualified);

        $this->assertSame(1, $this->enrollmentsFor($workflow));

        $context = $this->source(WorkflowTriggerType::OpportunityStageChanged)->contextForEnrollment(AutomationEnrollment::query()->sole());

        $this->assertSame((int) $newInquiry->id, $context->fromStageId);
        $this->assertSame((int) $qualified->id, $context->toStageId);
        $this->assertSame((int) $qualified->id, $context->stageId);
        $this->assertSame((int) $pipeline->id, $context->pipelineId);
        $this->assertNull($context->outcome);
    }

    public function test_stage_filters_admit_only_the_matching_move(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $newInquiry = $this->stageKeyed($pipeline, 'new_inquiry');
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $proposal = $this->stageKeyed($pipeline, 'proposal_sent');

        $intoProposal = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityStageChanged, ['to_stage_id' => (int) $proposal->id]);
        $outOfInquiry = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityStageChanged, [
            'pipeline_id' => (int) $pipeline->id,
            'from_stage_id' => (int) $newInquiry->id,
        ]);

        $deal = $this->deal($business, $pipeline);
        $service = app(CrmOpportunityService::class);

        $service->moveToStage($deal, $qualified);
        $this->assertSame(0, $this->enrollmentsFor($intoProposal), 'Moving to Qualified is not a move to Proposal sent.');
        $this->assertSame(1, $this->enrollmentsFor($outOfInquiry), 'It did leave New inquiry, in this pipeline.');

        $this->finishJourneys();
        $service->moveToStage($deal->fresh(), $proposal);
        $this->assertSame(1, $this->enrollmentsFor($intoProposal));
        $this->assertSame(1, $this->enrollmentsFor($outOfInquiry), 'Qualified → Proposal sent did not leave New inquiry.');
    }

    public function test_opportunity_marked_won_enrolls_once(): void
    {
        [, $business] = $this->crmTenant();
        $deal = $this->deal($business);
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityWon);
        $lostWorkflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityLost);

        app(CrmOpportunityService::class)->markWon($deal);

        $this->assertSame(1, $this->enrollmentsFor($workflow));
        $this->assertSame(0, $this->enrollmentsFor($lostWorkflow), 'Won is not lost.');

        $context = $this->source(WorkflowTriggerType::OpportunityWon)->contextForEnrollment(AutomationEnrollment::query()->sole());
        $this->assertSame('won', $context->outcome);
        $this->assertSame((int) $deal->id, $context->opportunityId);
    }

    public function test_opportunity_marked_lost_enrolls_once(): void
    {
        [, $business] = $this->crmTenant();
        $deal = $this->deal($business);
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityLost);
        $wonWorkflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityWon);

        app(CrmOpportunityService::class)->markLost($deal, 'Went with another studio');

        $this->assertSame(1, $this->enrollmentsFor($workflow));
        $this->assertSame(0, $this->enrollmentsFor($wonWorkflow));
        $this->assertSame('lost', $this->source(WorkflowTriggerType::OpportunityLost)->contextForEnrollment(AutomationEnrollment::query()->sole())->outcome);
    }

    public function test_each_trigger_listens_only_for_its_own_event(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);

        $workflows = [];
        foreach ([WorkflowTriggerType::OpportunityCreated, WorkflowTriggerType::OpportunityStageChanged, WorkflowTriggerType::OpportunityWon, WorkflowTriggerType::OpportunityLost] as $type) {
            $workflows[$type->value] = $this->crmWorkflow($business, $type);
        }

        $this->deal($business, $pipeline);

        $this->assertSame(
            ['opportunity_created' => 1, 'opportunity_stage_changed' => 0, 'opportunity_won' => 0, 'opportunity_lost' => 0],
            array_map(fn (AutomationWorkflow $workflow): int => $this->enrollmentsFor($workflow), $workflows),
        );
    }

    // =================================================================
    // Idempotency
    // =================================================================

    public function test_a_replayed_event_does_not_enroll_twice(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityStageChanged);
        $deal = $this->deal($business, $pipeline);
        $from = $deal->stage;
        $to = $this->stageKeyed($pipeline, 'qualified');

        app(CrmOpportunityService::class)->moveToStage($deal, $to);
        $this->assertSame(1, $this->enrollmentsFor($workflow));

        // Close the journey, so neither the active-contact guard nor anything but
        // the occurrence key could refuse the replay.
        $this->finishJourneys();

        $replay = new CrmOpportunityStageChanged(
            (int) $business->id, (int) $deal->id, (int) $deal->contact_id, (int) $pipeline->id,
            (int) $to->id, $to->semantic_key, (int) $this->latestHistory($deal)->id, null,
            fromStageId: (int) $from->id, fromStageSemanticKey: $from->semantic_key,
        );

        app(EnrollFromCrmOpportunityEvent::class)->handle($replay);
        app(EnrollFromCrmOpportunityEvent::class)->handle($replay);

        $this->assertSame(1, $this->enrollmentsFor($workflow), 'One change, one key, one enrollment — however often it is delivered.');
    }

    // =================================================================
    // Isolation and eligibility
    // =================================================================

    public function test_another_businesss_workflow_never_fires(): void
    {
        [, $business] = $this->crmTenant();
        [, $other] = $this->crmTenant('Other Studio', 'Other Account');
        $theirs = $this->crmWorkflow($other, WorkflowTriggerType::OpportunityCreated);

        $this->deal($business);

        $this->assertSame(0, $this->enrollmentsFor($theirs));
    }

    /** An event naming one Business but another Business's change is refused, not trusted. */
    public function test_an_event_whose_change_belongs_to_another_business_enrolls_nobody(): void
    {
        [, $business] = $this->crmTenant();
        [, $other] = $this->crmTenant('Other Studio', 'Other Account');
        $theirs = $this->crmWorkflow($other, WorkflowTriggerType::OpportunityCreated);

        $deal = $this->deal($business);
        $history = $this->latestHistory($deal);

        $forged = new CrmOpportunityCreated((int) $other->id, (int) $deal->id, (int) $deal->contact_id, (int) $deal->pipeline_id, (int) $deal->stage_id, null, (int) $history->id, null);
        $result = $this->source(WorkflowTriggerType::OpportunityCreated)->handle($forged);

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame([CrmOpportunityTriggerSource::SKIPPED_NO_CHANGE => 1], $result['skipped']);
        $this->assertSame(0, $this->enrollmentsFor($theirs));
    }

    public function test_an_event_that_misnames_its_change_enrolls_nobody(): void
    {
        [, $business] = $this->crmTenant();
        $wonWorkflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityWon);
        $deal = $this->deal($business);

        // A "won" event pointing at the deal's CREATED history row.
        $misnamed = new CrmOpportunityWon((int) $business->id, (int) $deal->id, (int) $deal->contact_id, (int) $deal->pipeline_id, (int) $deal->stage_id, null, (int) $this->latestHistory($deal)->id, null);

        $this->assertSame(0, $this->source(WorkflowTriggerType::OpportunityWon)->handle($misnamed)['enrolled']);
        $this->assertSame(0, $this->enrollmentsFor($wonWorkflow));
    }

    public function test_a_paused_workflow_does_not_fire_and_a_resumed_one_does(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityCreated);

        app(WorkflowLifecycle::class)->pause($workflow);
        $this->deal($business, $pipeline, title: 'While paused');
        $this->assertSame(0, $this->enrollmentsFor($workflow), 'Paused: no new contacts enter.');

        app(WorkflowLifecycle::class)->resume($workflow->fresh());
        $this->deal($business, $pipeline, title: 'After resume');
        $this->assertSame(1, $this->enrollmentsFor($workflow));
    }

    public function test_an_unpublished_or_archived_workflow_does_not_fire(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);

        $draftOnly = app(WorkflowDraftService::class)->createWorkflowWithDraft($business, 'Never published', WorkflowTriggerType::OpportunityCreated);
        $archived = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityCreated);
        app(WorkflowLifecycle::class)->archive($archived);

        $this->deal($business, $pipeline);

        $this->assertSame(0, $this->enrollmentsFor($draftOnly));
        $this->assertSame(0, $this->enrollmentsFor($archived));
    }

    public function test_a_deal_whose_contact_is_gone_enrolls_nobody(): void
    {
        [, $business] = $this->crmTenant();
        $workflow = $this->crmWorkflow($business, WorkflowTriggerType::OpportunityWon);
        $deal = $this->deal($business);

        Event::fake([CrmOpportunityWon::class]);
        app(CrmOpportunityService::class)->markWon($deal);

        $event = null;
        Event::assertDispatched(CrmOpportunityWon::class, function (CrmOpportunityWon $won) use (&$event): bool {
            $event = $won;

            return true;
        });

        // The contact is deleted before the queued work runs (the CRM nulls it).
        DB::table('crm_opportunities')->where('id', $deal->id)->update(['contact_id' => null]);

        $result = $this->source(WorkflowTriggerType::OpportunityWon)->handle($event);

        $this->assertSame([CrmOpportunityTriggerSource::SKIPPED_NO_CONTACT => 1], $result['skipped']);
        $this->assertSame(0, $this->enrollmentsFor($workflow));
    }

    // =================================================================
    // Wiring and separation
    // =================================================================

    public function test_the_events_are_after_commit_and_the_listener_is_queued_on_automation(): void
    {
        $this->assertTrue(is_subclass_of(CrmOpportunityEvent::class, ShouldDispatchAfterCommit::class));
        $this->assertTrue(is_subclass_of(EnrollFromCrmOpportunityEvent::class, ShouldQueue::class));

        $listener = app(EnrollFromCrmOpportunityEvent::class);
        $this->assertSame('automation', $listener->queue);
        $this->assertSame(1, $listener->tries, 'A redelivery composes the same key, so a retry buys nothing.');

        Event::fake([CrmOpportunityCreated::class, CrmOpportunityStageChanged::class, CrmOpportunityWon::class, CrmOpportunityLost::class]);

        foreach ([CrmOpportunityCreated::class, CrmOpportunityStageChanged::class, CrmOpportunityWon::class, CrmOpportunityLost::class] as $event) {
            Event::assertListening($event, EnrollFromCrmOpportunityEvent::class);
            $this->assertNotNull(WorkflowTriggerType::tryFrom($event::NAME), "{$event}'s name is its trigger type.");
        }

        $this->assertSame('crm_opportunity_history:', CrmOpportunityTriggerContext::OCCURRENCE_PREFIX);
    }

    public function test_the_four_crm_triggers_have_sources_and_message_received_keeps_its_own(): void
    {
        $registry = app(TriggerSourceRegistry::class);

        foreach ([WorkflowTriggerType::OpportunityCreated, WorkflowTriggerType::OpportunityStageChanged, WorkflowTriggerType::OpportunityWon, WorkflowTriggerType::OpportunityLost] as $type) {
            $source = $registry->for($type);
            $this->assertInstanceOf(CrmOpportunityTriggerSource::class, $source);
            $this->assertSame($type, $source->triggerType());
            $this->assertTrue($registry->available($type));
        }

        $this->assertInstanceOf(MessageReceivedTriggerSource::class, $registry->for(WorkflowTriggerType::MessageReceived));
    }

    /** The CRM domain only: nothing here reads or names the Advisor's recommendations. */
    public function test_the_crm_trigger_code_never_touches_the_advisor_opportunity_domain(): void
    {
        foreach ([
            'app/Library/Automation/Workflow/Triggers/CrmOpportunityTriggerSource.php',
            'app/Library/Automation/Workflow/Triggers/CrmOpportunityTriggerContext.php',
            'app/Listeners/Automation/Workflow/EnrollFromCrmOpportunityEvent.php',
        ] as $path) {
            $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(base_path($path))) ?? '';

            foreach (['App\Models\Opportunity;', 'App\Events\Opportunity\\', 'App\Library\Opportunity\\'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$path} must not reference the Advisor domain ({$forbidden}).");
            }

            // A string naming the Advisor's `opportunities` table (crm_opportunities is the CRM's).
            $this->assertDoesNotMatchRegularExpression("/['\"]opportunities\\b/", $code, "{$path} must not read the Advisor's opportunities table.");
        }

        // And the CRM never learned about Automations.
        $crm = (string) file_get_contents(base_path('app/Library/Crm/CrmOpportunityService.php'));
        $this->assertStringNotContainsString('Automation', preg_replace('#/\*.*?\*/#s', '', $crm) ?? '');
    }
}
