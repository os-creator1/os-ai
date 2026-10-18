<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\TestCase;

/**
 * Automations — the Enrollment history and Execution logs tabs: who entered, where
 * they are, what happened — in customer words, by number (never an id), with a bounded
 * reason for anything that did not run, and nothing from another workflow or Business.
 */
class JourneyPanelsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;
    use CallsWorkflowRoutes;

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
        $this->authenticateAs($this->world['customer']);
    }

    private function journeyWithASkippedMove(): array
    {
        $pipeline = $this->formsPipeline($this->world['business']);
        $stage = \App\Models\CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->skip(1)->firstOrFail();
        $workflow = $this->xManualWorkflow($this->world['business'], [
            $this->xNode('move_opportunity', ['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]),
        ]);
        $enrollment = $this->xAdvance($this->xEnroll($workflow, $this->world['contact'], 'k-' . uniqid()));

        return [$workflow, $enrollment];
    }

    public function test_the_history_names_the_person_by_number_and_the_status_in_words(): void
    {
        [$workflow, $enrollment] = $this->journeyWithASkippedMove();

        $rows = $this->callJson('GET', $this->routeUrl('enrollments.index', $this->world['workspace'], $this->world['business'], $workflow))
            ->assertOk()->json('enrollments');

        $this->assertCount(1, $rows);
        $this->assertSame((string) $this->world['contact']->phone, $rows[0]['contact_label']);
        $this->assertSame('Completed', $rows[0]['status_label']);
        $this->assertNull($rows[0]['exit_reason_label']);
        $this->assertSame($enrollment->uid, $rows[0]['uid']);
    }

    public function test_a_step_that_did_not_run_says_why_in_a_bounded_customer_sentence(): void
    {
        [$workflow, $enrollment] = $this->journeyWithASkippedMove();

        $steps = $this->callJson('GET', $this->routeUrl('enrollments.logs', $this->world['workspace'], $this->world['business'], $workflow, $enrollment))
            ->assertOk()->json('steps');
        $move = collect($steps)->firstWhere('node_type', 'move_opportunity');

        $this->assertSame('There is no open opportunity to move', $move['error_label']);
        $this->assertStringNotContainsString('opportunity_not_found', json_encode($move['error_label']));
    }

    public function test_an_unknown_internal_code_never_reaches_the_screen_as_a_label(): void
    {
        [$workflow, $enrollment] = $this->journeyWithASkippedMove();
        DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->where('node_type', 'move_opportunity')
            ->update(['safe_error_summary' => 'some_internal_code: SQLSTATE[23000] secret']);

        $steps = $this->callJson('GET', $this->routeUrl('enrollments.logs', $this->world['workspace'], $this->world['business'], $workflow, $enrollment))
            ->assertOk()->json('steps');
        $move = collect($steps)->firstWhere('node_type', 'move_opportunity');

        $this->assertSame('This step could not run.', $move['error_label']);
    }

    public function test_the_builder_page_carries_both_panes(): void
    {
        [$workflow] = $this->journeyWithASkippedMove();

        $html = $this->get($this->routeUrl('show', $this->world['workspace'], $this->world['business'], $workflow))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="wf-journeys-history"', $html);
        $this->assertStringContainsString('data-role="wf-journeys-logs"', $html);
        $this->assertStringNotContainsString('isn’t shown in the builder yet', $html);
    }

    public function test_the_history_of_one_workflow_is_never_another_businesss(): void
    {
        [$workflow] = $this->journeyWithASkippedMove();
        $other = $this->sendableTenant('Other Studio');
        $theirs = $this->xManualWorkflow($other['business'], []);
        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count());

        $this->callJson('GET', $this->routeUrl('enrollments.index', $this->world['workspace'], $this->world['business'], $theirs))->assertStatus(404);
    }
}
