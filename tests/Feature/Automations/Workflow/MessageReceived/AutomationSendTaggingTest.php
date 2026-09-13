<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\AutomationSendContext;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationStepRun;
use App\Models\Reports;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-F §10.1 — every automation send carries its step run.
 *
 * The mark is what the self-reply rule and the causation chain are built on, so
 * it is proved at three levels: the scope and the model hook on their own, the
 * real SendSmsNodeExecutor run by the real advancer, and the schema's own
 * guarantees about the column.
 */
class AutomationSendTaggingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;
    use BuildsInboundFixtures;

    private function context(): AutomationSendContext
    {
        return app(AutomationSendContext::class);
    }

    // =================================================================
    // The scope and the hook
    // =================================================================

    public function test_an_outbound_row_created_inside_a_send_scope_carries_the_step_run(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $this->contactFor($business), 'manual:1');
        $stepRunId = $this->stepRunOn($enrollment);

        $report = $this->context()->during($stepRunId, fn () => $this->outbound($business, '14155553001'));

        $this->assertSame($stepRunId, (int) $report->fresh()->automation_step_run_id);
    }

    public function test_a_manually_sent_message_carries_no_mark(): void
    {
        [, $business] = $this->entitledTenant();

        // No scope: a person in the inbox, a campaign, an API call.
        $report = $this->outbound($business, '14155553002');

        $this->assertNull($report->fresh()->automation_step_run_id, 'A manual send must never look like automation output.');
    }

    public function test_an_incoming_row_is_never_marked_even_inside_a_scope(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $this->contactFor($business), 'manual:2');
        $stepRunId = $this->stepRunOn($enrollment);

        $event = $this->context()->during($stepRunId, fn () => $this->legacyInbound($business, '14155553003'));

        $this->assertNull(
            Reports::query()->findOrFail((int) substr($event->occurrenceKey, strlen('report:')))->automation_step_run_id,
            'An inbound message is never automation output.',
        );
    }

    public function test_the_scope_ends_with_the_send_even_when_it_throws(): void
    {
        try {
            $this->context()->during(123, function (): never {
                throw new \RuntimeException('provider blew up');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull($this->context()->currentStepRunId(), 'A failed send must not leak its mark into the next one.');
    }

    public function test_a_nested_scope_restores_the_outer_one(): void
    {
        $this->context()->during(1, function (): void {
            $this->context()->during(2, fn () => $this->assertSame(2, $this->context()->currentStepRunId()));

            $this->assertSame(1, $this->context()->currentStepRunId());
        });

        $this->assertNull($this->context()->currentStepRunId());
    }

    public function test_the_context_is_a_singleton_so_the_hook_sees_the_executors_scope(): void
    {
        $this->assertSame(app(AutomationSendContext::class), app(AutomationSendContext::class));
    }

    // =================================================================
    // The real executor, run by the real advancer
    // =================================================================

    public function test_a_workflow_send_marks_the_row_its_transport_writes_with_that_step_run(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);
        [$workflow] = $this->publishWorkflow($business, [$this->smsStep('Thanks for writing'), $this->endStep()]);
        $contact = $this->contactFor($business);

        // A send core that writes its Reports row exactly as a transport branch
        // does — the executor is not told how, and must not need to be.
        $mock = Mockery::mock(CampaignRepository::class);
        $mock->shouldReceive('checkQuickSendValidation')->andReturnUsing(fn (array $input) => response()->json([
            'status' => 'success',
            'sender_id' => $input['sender_id'] ?? null,
            'sms_type' => $input['sms_type'] ?? 'plain',
            'user_id' => $input['user_id'] ?? null,
        ]));
        $mock->shouldReceive('quickSend')->once()->andReturnUsing(function ($campaign, array $input) {
            Reports::create([
                'user_id' => $input['user_id'],
                'business_id' => $campaign->business_id,
                'from' => (string) $input['sender_id'],
                'to' => $input['country_code'] . $input['recipient'],
                'message' => $input['message'],
                'sms_type' => 'plain',
                'status' => 'Delivered',
                'customer_status' => 'Delivered',
                'direction' => Reports::DIRECTION_OUTGOING,
                'cost' => 0,
                'sms_count' => 1,
            ]);

            return response()->json(['status' => 'success', 'message' => 'sent']);
        });
        $this->app->instance(CampaignRepository::class, $mock);
        $this->app->forgetInstance(NodeExecutorRegistry::class);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'contact:' . $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $sendStep = AutomationStepRun::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('node_type', 'send_sms')
            ->sole();

        $report = Reports::query()->where('direction', Reports::DIRECTION_OUTGOING)->sole();

        $this->assertSame((int) $sendStep->id, (int) $report->automation_step_run_id);
        $this->assertNull($this->context()->currentStepRunId(), 'The scope closed with the send.');
    }

    // =================================================================
    // The schema
    // =================================================================

    public function test_the_column_is_a_nullable_reference_and_existing_rows_are_unmarked(): void
    {
        $this->assertTrue(Schema::hasColumn('reports', 'automation_step_run_id'));

        [, $business] = $this->entitledTenant();

        DB::table('reports')->insert([
            'uid' => uniqid(), 'user_id' => $business->customer_id, 'business_id' => $business->id,
            'to' => '14155553009', 'sms_type' => 'plain', 'status' => 'Delivered',
            'direction' => 'outgoing', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('reports')->where('to', '14155553009')->value('automation_step_run_id'));
    }

    public function test_deleting_the_journey_keeps_the_message_and_only_drops_the_mark(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $this->contactFor($business), 'manual:3');
        $report = $this->outbound($business, '14155553010', $this->stepRunOn($enrollment));

        // Step runs cascade from their enrollment; the message must not follow
        // them. Deliberately unlike B4's `reports.automation_id`, which cascades.
        DB::table('automation_enrollments')->where('id', $enrollment->id)->delete();

        $this->assertNotNull(Reports::query()->find($report->id), "The Business's record of what was said survives.");
        $this->assertNull($report->fresh()->automation_step_run_id);
    }

    public function test_a_mark_must_reference_a_real_step_run(): void
    {
        [, $business] = $this->entitledTenant();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->outbound($business, '14155553011', 987654321);
    }
}
