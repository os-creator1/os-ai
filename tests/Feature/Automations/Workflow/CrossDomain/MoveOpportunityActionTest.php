<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\TestCase;

/**
 * Automations x CRM — the Move opportunity action.
 *
 * It moves a deal only through CrmOpportunityService::moveToStage(); which deal is
 * resolved safely at run time; a foreign or archived stage fails closed; a deal at
 * another Location is never touched; and the move can never feed itself.
 */
class MoveOpportunityActionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;

    /** @var array<string, mixed> */
    private array $world;

    private CrmPipeline $pipeline;

    /** @var list<CrmPipelineStage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
        $this->pipeline = $this->standardPipeline($this->world['business']);
        $this->stages = CrmPipelineStage::query()->where('pipeline_id', $this->pipeline->id)->orderBy('position')->get()->all();
    }

    private function moveTo(CrmPipelineStage $stage, array $triggerConfig = [], ?CrmPipeline $pipeline = null): \App\Models\AutomationWorkflow
    {
        return $this->xManualWorkflow(
            $this->world['business'],
            [$this->xNode('move_opportunity', ['pipeline_id' => ($pipeline ?? $this->pipeline)->id, 'stage_id' => $stage->id])],
            $triggerConfig,
        );
    }

    private function newDeal(?\App\Models\Contacts $contact = null): CrmOpportunity
    {
        return app(CrmOpportunityService::class)->create(
            $this->world['business'],
            $this->pipeline,
            $contact ?? $this->world['contact'],
            'Photo booth for Sam',
            null,
            $this->stages[0],
        );
    }

    private function stageOf(CrmOpportunity $deal): int
    {
        return (int) DB::table('crm_opportunities')->where('id', $deal->id)->value('stage_id');
    }

    private function runJourney(\App\Models\AutomationWorkflow $workflow, ?\App\Models\Contacts $contact = null, ?\App\Models\BusinessLocation $pinned = null): AutomationEnrollment
    {
        return $this->xAdvance($this->xEnroll($workflow, $contact ?? $this->world['contact'], 'k-' . uniqid(), $pinned));
    }

    public function test_the_contacts_one_open_deal_moves_through_the_crm_service_and_a_replay_moves_nothing(): void
    {
        $deal = $this->newDeal();
        $workflow = $this->moveTo($this->stages[2]);

        $enrollment = $this->runJourney($workflow);

        $this->assertSame((int) $this->stages[2]->id, $this->stageOf($deal));
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $step = $this->xStep($enrollment, 'move_opportunity');
        $this->assertSame(StepRunStatus::Succeeded, $step->status);
        $this->assertSame('Moved to ' . $this->stages[2]->name, $step->safe_result_summary);
        $history = DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->where('event', 'stage_changed')->count();
        $this->assertSame(1, $history, 'The CRM recorded exactly one stage change.');

        // A second journey into the same step finds the deal already there: nothing changes, nothing is announced.
        $again = $this->runJourney($workflow);
        $this->assertSame('Already in that stage', $this->xStep($again, 'move_opportunity')->safe_result_summary);
        $this->assertSame($history, DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->where('event', 'stage_changed')->count());
    }

    public function test_the_deal_the_trigger_names_is_the_one_moved_when_the_contact_has_several(): void
    {
        $older = $this->newDeal();

        $workflow = $this->triggerWorkflow(
            $this->world['business'],
            WorkflowTriggerType::OpportunityCreated,
            [],
            [$this->xNode('move_opportunity', ['pipeline_id' => $this->pipeline->id, 'stage_id' => $this->stages[1]->id]), $this->endStep()],
        );

        // Creating the newer deal is what starts the journey, through the CRM's own event.
        $newer = $this->newDeal();
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();
        $this->xAdvance($enrollment);

        $this->assertSame((int) $this->stages[1]->id, $this->stageOf($newer), 'The deal the fact names is moved.');
        $this->assertSame((int) $this->stages[0]->id, $this->stageOf($older), 'The contact\'s other deal is left alone.');
    }

    public function test_two_open_deals_with_no_naming_fact_move_nothing(): void
    {
        $first = $this->newDeal();
        $second = $this->newDeal();
        $workflow = $this->moveTo($this->stages[2]);

        $enrollment = $this->runJourney($workflow);

        $step = $this->xStep($enrollment, 'move_opportunity');
        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('opportunity_ambiguous', $step->safe_error_summary);
        $this->assertSame([(int) $this->stages[0]->id, (int) $this->stages[0]->id], [$this->stageOf($first), $this->stageOf($second)]);
    }

    public function test_no_deal_and_a_closed_deal_are_both_a_bounded_skip(): void
    {
        $workflow = $this->moveTo($this->stages[2]);
        $this->assertSame('opportunity_not_found', $this->xStep($this->runJourney($workflow), 'move_opportunity')->safe_error_summary);

        $won = $this->newDeal();
        app(CrmOpportunityService::class)->markWon($won);

        $this->assertSame('opportunity_not_found', $this->xStep($this->runJourney($workflow), 'move_opportunity')->safe_error_summary, 'A closed deal is not a candidate.');
    }

    public function test_another_businesss_pipeline_or_stage_cannot_be_published_and_fails_closed_if_tampered_in(): void
    {
        $other = $this->sendableTenant('Other Studio');
        $theirPipeline = $this->standardPipeline($other['business']);
        $theirStage = CrmPipelineStage::query()->where('pipeline_id', $theirPipeline->id)->orderBy('position')->firstOrFail();

        foreach ([
            'foreign pipeline' => [$theirPipeline->id, $theirStage->id],
            'foreign stage in our pipeline' => [$this->pipeline->id, $theirStage->id],
            'stage of another pipeline' => [$this->pipeline->id, $this->stages[0]->id + 99999],
        ] as $label => [$pipelineId, $stageId]) {
            try {
                $this->xManualWorkflow($this->world['business'], [$this->xNode('move_opportunity', ['pipeline_id' => $pipelineId, 'stage_id' => $stageId])]);
                $this->fail("{$label} must not publish.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('does not belong to this business', json_encode($exception->errors()), $label);
            }
        }

        // A version that outlives what was true at publish: the stage is archived, then another Business's id is forced in.
        $deal = $this->newDeal();
        $workflow = $this->moveTo($this->stages[2]);
        DB::table('automation_workflow_nodes')->where('version_id', $workflow->published_version_id)->where('node_type', 'move_opportunity')
            ->update(['config' => json_encode(['pipeline_id' => $this->pipeline->id, 'stage_id' => $theirStage->id])]);

        $step = $this->xStep($this->runJourney($workflow), 'move_opportunity');

        $this->assertSame(StepRunStatus::Failed, $step->status);
        $this->assertSame('stage_unavailable', $step->safe_error_summary);
        $this->assertSame((int) $this->stages[0]->id, $this->stageOf($deal));
    }

    public function test_an_archived_stage_cannot_be_published_and_fails_closed_at_run_time(): void
    {
        $extra = app(CrmPipelineService::class)->addStage($this->pipeline, 'Waiting');
        $workflow = $this->moveTo($extra);
        $deal = $this->newDeal();
        app(CrmPipelineService::class)->archiveStage($extra, $this->stages[1]);

        $step = $this->xStep($this->runJourney($workflow), 'move_opportunity');

        $this->assertSame('stage_unavailable', $step->safe_error_summary);
        $this->assertSame((int) $this->stages[0]->id, $this->stageOf($deal));

        try {
            $this->moveTo($extra->fresh());
            $this->fail('An archived stage must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('archived', json_encode($exception->errors()));
        }
    }

    // =================================================================
    // Location
    // =================================================================

    public function test_a_journey_never_moves_a_deal_that_belongs_to_another_location(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $deal = $this->xDeal($this->world['business'], $this->world['contact'], $uptown);
        $workflow = $this->moveTo($this->stages[2], ['business_location_id' => $this->world['location']->id]);

        $step = $this->xStep($this->runJourney($workflow, null, $this->world['location']), 'move_opportunity');

        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('resource_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame((int) $this->stages[0]->id, $this->stageOf($deal));
    }

    public function test_a_deal_at_the_pinned_location_or_with_none_is_moved(): void
    {
        $deal = $this->xDeal($this->world['business'], $this->world['contact'], $this->world['location']);
        $workflow = $this->moveTo($this->stages[1], ['business_location_id' => $this->world['location']->id]);

        $this->runJourney($workflow, null, $this->world['location']);
        $this->assertSame((int) $this->stages[1]->id, $this->stageOf($deal));

        $noLocation = $this->newDeal($this->xContact($this->world, $this->world['location'], '14155558801'));
        DB::table('crm_opportunities')->where('id', $noLocation->id)->update(['location_id' => null]);
        $contact = \App\Models\Contacts::query()->findOrFail($noLocation->contact_id);
        $this->runJourney($workflow, $contact, $this->world['location']);
        $this->assertSame((int) $this->stages[1]->id, $this->stageOf($noLocation), 'A deal with no Location of its own is Business-level.');
    }

    public function test_a_contact_who_moved_away_is_left_alone(): void
    {
        $deal = $this->newDeal();
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $workflow = $this->moveTo($this->stages[2], ['business_location_id' => $this->world['location']->id]);
        $enrollment = $this->xEnroll($workflow, $this->world['contact'], 'k', $this->world['location']);
        DB::table('contacts')->where('id', $this->world['contact']->id)->update(['location_id' => $uptown->id]);

        $step = $this->xStep($this->xAdvance($enrollment), 'move_opportunity');

        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame((int) $this->stages[0]->id, $this->stageOf($deal));
    }

    // =================================================================
    // Loop prevention
    // =================================================================

    public function test_two_workflows_that_move_a_deal_back_and_forth_stop_within_the_causation_limit(): void
    {
        $toBooked = $this->stages[1];
        $toQuoted = $this->stages[2];

        // A: when a deal ENTERS stage 1, move it to stage 2.  B: when it enters stage 2, move it back to stage 1.
        $a = $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::OpportunityStageChanged, ['to_stage_id' => $toBooked->id], [
            $this->xNode('move_opportunity', ['pipeline_id' => $this->pipeline->id, 'stage_id' => $toQuoted->id]), $this->endStep(),
        ]);
        $b = $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::OpportunityStageChanged, ['to_stage_id' => $toQuoted->id], [
            $this->xNode('move_opportunity', ['pipeline_id' => $this->pipeline->id, 'stage_id' => $toBooked->id]), $this->endStep(),
        ]);

        $deal = $this->newDeal();
        // A PERSON moves the deal into stage 1: depth 0.
        app(CrmOpportunityService::class)->moveToStage($deal, $toBooked);

        // Drive every journey to rest, as the queue would — bounded, so a loop fails the test instead of hanging it.
        for ($round = 0; $round < 12; $round++) {
            $active = AutomationEnrollment::query()->where('status', EnrollmentStatus::Active->value)->get();

            if ($active->isEmpty()) {
                break;
            }

            $active->each(fn (AutomationEnrollment $e) => $this->xAdvance($e));
        }

        $this->assertLessThan(12, $round, 'The chain must come to rest by itself.');
        $total = AutomationEnrollment::query()->whereIn('workflow_id', [$a->id, $b->id])->count();
        $this->assertLessThanOrEqual(4, $total, 'MAX_CAUSATION_DEPTH bounds the ping-pong.');
        $this->assertGreaterThanOrEqual(2, $total, 'And it did start: each workflow reacted to the other\'s move.');
        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $a->id)->where('causation_depth', 0)->count(), 'Only the person\'s own move is depth 0.');
        $this->assertSame(0, AutomationEnrollment::query()->where('workflow_id', $b->id)->where('causation_depth', 0)->count());
    }
}
