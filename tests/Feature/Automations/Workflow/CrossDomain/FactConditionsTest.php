<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Crm\CrmOpportunityService;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\BusinessDocument;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\TestCase;

/**
 * Automations — the fact-backed If / Else conditions: the CRM deal, the document, the
 * payment and the appointment behind a journey, read LIVE from the owning domain's
 * own row, with a closed vocabulary and no expression language.
 */
class FactConditionsTest extends TestCase
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
        Notification::fake();
        $this->pipeline = $this->standardPipeline($this->world['business']);
        $this->stages = CrmPipelineStage::query()->where('pipeline_id', $this->pipeline->id)->orderBy('position')->get()->all();
    }

    private function condition(string $subject, string $operator, mixed $operand = null): array
    {
        return ['subject' => $subject, 'operator' => $operator] + ($operand === null ? [] : ['operand' => $operand]);
    }

    private function branch(array ...$conditions): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => $conditions],
            'yes' => [$this->endStep()],
            'no' => [$this->endStep()],
        ];
    }

    private function workflow(WorkflowTriggerType $trigger, array ...$conditions): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->world['business'], $trigger, ['enrollment_policy' => 'once_per_occurrence', 'enrollment_policy_source' => 'user'], [$this->branch(...$conditions)]);
    }

    /** Evaluate the branch for a journey on this key; returns true for the yes lane. */
    private function takes(AutomationWorkflow $workflow, string $occurrence): bool
    {
        // The fact is re-derived from the occurrence key itself, so the key stays the real
        // one and an earlier journey of this workflow on it is cleared (once per occurrence).
        DB::table('automation_enrollments')->where('workflow_id', $workflow->id)->delete();
        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $this->world['contact'], $occurrence);
        $this->assertNotNull($enrollment);
        $this->xAdvance($enrollment);

        $summary = (string) DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->where('node_type', 'if_else')->value('safe_result_summary');

        return $summary === 'Matched';
    }

    private function sent(): BusinessDocument
    {
        return app(\App\Library\Documents\DocumentManager::class)->send($this->draftDocument($this->world));
    }

    private function sign(BusinessDocument $document): void
    {
        $this->insertSignature((int) $document->id, (int) $document->current_version_id);
        DB::table('business_documents')->where('id', $document->id)->update(['status' => 'signed', 'signed_at' => now()]);
    }

    // =================================================================
    // Documents
    // =================================================================

    public function test_a_document_is_read_live_so_wait_then_if_not_signed_works(): void
    {
        $unsigned = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.signed', 'is_false'));
        $signed = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.signed', 'is_true'));
        $document = $this->sent();
        $key = 'document_version_sent:' . $document->current_version_id;

        $this->assertTrue($this->takes($unsigned, $key), 'Unsigned now.');
        $this->assertFalse($this->takes($signed, $key));

        $this->sign($document);

        // The journey starts from the very same fact; the condition reads the document AS IT IS.
        $this->assertFalse($this->takes($unsigned, $key));
        $this->assertTrue($this->takes($signed, $key));
    }

    public function test_document_status_and_paid_follow_the_documents_own_row(): void
    {
        $isSent = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.status', 'equals', 'sent'));
        $notPaid = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.paid', 'is_false'));
        $isPaid = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.paid', 'is_true'));
        $document = $this->sent();
        $key = 'document_version_sent:' . $document->current_version_id;

        $this->assertTrue($this->takes($isSent, $key));
        $this->assertTrue($this->takes($notPaid, $key));
        $this->assertFalse($this->takes($isPaid, $key));

        DB::table('business_documents')->where('id', $document->id)->update(['status' => 'paid', 'paid_at' => now()]);

        $this->assertFalse($this->takes($isSent, $key));
        $this->assertFalse($this->takes($notPaid, $key));
        $this->assertTrue($this->takes($isPaid, $key));
    }

    public function test_a_document_condition_on_a_journey_with_no_document_reads_nothing(): void
    {
        // Published against a document trigger, then enrolled with a key that names no document of this Business.
        $workflow = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.signed', 'is_true'));

        $this->assertFalse($this->takes($workflow, 'document_version_sent:999999'));
    }

    // =================================================================
    // Payments and appointments
    // =================================================================

    public function test_payment_status_reads_the_payment_the_trigger_is_about(): void
    {
        $succeeded = $this->workflow(WorkflowTriggerType::PaymentSucceeded, $this->condition('payment.status', 'equals', 'succeeded'));
        $failed = $this->workflow(WorkflowTriggerType::PaymentSucceeded, $this->condition('payment.status', 'equals', 'failed'));
        $document = $this->sent();
        $itemId = (int) DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $document->current_version_id)->value('id');
        $connectionId = (int) DB::table('business_stripe_connections')->where('business_id', $document->business_id)->value('id');
        $paymentId = $this->insertPayment((int) $document->business_id, (int) $document->id, $itemId, $connectionId, ['status' => 'succeeded', 'succeeded_at' => now()]);

        $this->assertTrue($this->takes($succeeded, 'document_payment_succeeded:' . $paymentId));
        $this->assertFalse($this->takes($failed, 'document_payment_succeeded:' . $paymentId));
    }

    public function test_appointment_status_reads_the_appointment_the_trigger_is_about(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $appointmentId = DB::table('appointments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_location_id' => $this->world['location']->id,
            'booking_type_id' => $type->id,
            'staff_user_id' => $this->world['customer']->user_id,
            'contact_id' => $this->world['contact']->id,
            'status' => 'cancelled',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cancelled = $this->workflow(WorkflowTriggerType::AppointmentCancelled, $this->condition('appointment.status', 'equals', 'cancelled'));
        $scheduled = $this->workflow(WorkflowTriggerType::AppointmentCancelled, $this->condition('appointment.status', 'equals', 'scheduled'));

        $this->assertTrue($this->takes($cancelled, 'appointment_cancelled:' . $appointmentId));
        $this->assertFalse($this->takes($scheduled, 'appointment_cancelled:' . $appointmentId));
    }

    // =================================================================
    // The CRM deal
    // =================================================================

    public function test_opportunity_stage_and_status_read_the_journeys_deal(): void
    {
        $deal = app(CrmOpportunityService::class)->create($this->world['business'], $this->pipeline, $this->world['contact'], 'Wedding', null, $this->stages[0]);
        $inFirst = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'equals', (string) $this->stages[0]->id));
        $notFirst = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'not_equals', (string) $this->stages[0]->id));
        $open = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.status', 'equals', 'open'));
        $won = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.status', 'equals', 'won'));

        $this->assertTrue($this->takes($inFirst, 'a'));
        $this->assertFalse($this->takes($notFirst, 'a'));
        $this->assertTrue($this->takes($open, 'a'));
        $this->assertFalse($this->takes($won, 'a'));

        app(CrmOpportunityService::class)->moveToStage($deal, $this->stages[2]);
        $this->assertFalse($this->takes($inFirst, 'b'));
        $this->assertTrue($this->takes($notFirst, 'b'));

        app(CrmOpportunityService::class)->markWon($deal->fresh());

        // A manual journey names no deal, so it reads the Contact's single OPEN deal — and a
        // won deal is no longer open: nothing is read, never a guess.
        $this->assertFalse($this->takes($won, 'b'));

        // A journey the deal's own "won" event started names the deal, so it reads it.
        $wonWorkflow = $this->workflow(WorkflowTriggerType::OpportunityWon, $this->condition('opportunity.status', 'equals', 'won'));
        $history = (int) DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->where('event', 'won')->value('id');
        $this->assertGreaterThan(0, $history);
        $this->assertTrue($this->takes($wonWorkflow, 'crm_opportunity_history:' . $history));
    }

    public function test_with_two_open_deals_and_no_naming_fact_the_deal_reads_as_not_set(): void
    {
        foreach ([1, 2] as $n) {
            app(CrmOpportunityService::class)->create($this->world['business'], $this->pipeline, $this->world['contact'], "Deal {$n}", null, $this->stages[0]);
        }

        $is = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'equals', (string) $this->stages[0]->id));

        $this->assertFalse($this->takes($is, 'a'), 'Several deals is never read as one particular deal.');
    }

    public function test_a_stage_of_another_business_asserts_nothing_either_way(): void
    {
        $other = $this->sendableTenant('Other Studio');
        $theirs = CrmPipelineStage::query()->where('pipeline_id', $this->standardPipeline($other['business'])->id)->orderBy('position')->firstOrFail();
        app(CrmOpportunityService::class)->create($this->world['business'], $this->pipeline, $this->world['contact'], 'Wedding', null, $this->stages[0]);
        $workflow = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'equals', (string) $this->stages[0]->id));
        $tampered = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'not_equals', (string) $this->stages[0]->id));

        foreach ([$workflow, $tampered] as $w) {
            DB::table('automation_workflow_nodes')->where('version_id', $w->published_version_id)->where('node_type', 'if_else')
                ->update(['config' => json_encode(['match' => 'all', 'conditions' => [['subject' => 'opportunity.stage', 'operator' => $w === $workflow ? 'equals' : 'not_equals', 'operand' => (string) $theirs->id]]])]);
        }

        $this->assertFalse($this->takes($workflow, 'a'));
        $this->assertFalse($this->takes($tampered, 'a'), 'A forged reference asserts nothing: not even "is not".');
    }

    // =================================================================
    // Publish: the vocabulary is closed, and fits the trigger
    // =================================================================

    public function test_fact_conditions_are_refused_where_they_could_never_read_anything_or_are_malformed(): void
    {
        $other = $this->sendableTenant('Other Studio');
        $theirStage = CrmPipelineStage::query()->where('pipeline_id', $this->standardPipeline($other['business'])->id)->orderBy('position')->firstOrFail();

        foreach ([
            'a document condition on a manual trigger' => [WorkflowTriggerType::ManualEnrollment, $this->condition('document.signed', 'is_true'), 'does not provide'],
            'a payment condition on a document trigger' => [WorkflowTriggerType::DocumentSent, $this->condition('payment.status', 'equals', 'failed'), 'does not provide'],
            'an appointment condition on a document trigger' => [WorkflowTriggerType::DocumentSent, $this->condition('appointment.status', 'equals', 'cancelled'), 'does not provide'],
            'an unknown document status' => [WorkflowTriggerType::DocumentSent, $this->condition('document.status', 'equals', 'bogus'), 'known values'],
            'an unknown deal status' => [WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.status', 'equals', 'pending'), 'known values'],
            'a text operator on a boolean' => [WorkflowTriggerType::DocumentSent, $this->condition('document.signed', 'contains', 'x'), 'comparison'],
            'a stage of another business' => [WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'equals', (string) $theirStage->id), 'does not belong to this business'],
            'a stage that is not a number' => [WorkflowTriggerType::ManualEnrollment, $this->condition('opportunity.stage', 'equals', 'first'), 'a stage'],
            'an invented subject' => [WorkflowTriggerType::ManualEnrollment, $this->condition('document.total', 'equals', '10'), 'cannot read'],
        ] as $label => [$trigger, $condition, $expected]) {
            try {
                $this->workflow($trigger, $condition);
                $this->fail("{$label} must not publish.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($expected, json_encode($exception->errors()), $label);
            }
        }
    }

    public function test_each_fact_condition_publishes_where_its_trigger_provides_it(): void
    {
        $this->assertNotNull($this->workflow(WorkflowTriggerType::DocumentSigned, $this->condition('document.status', 'equals', 'signed'))->published_version_id);
        $this->assertNotNull($this->workflow(WorkflowTriggerType::PaymentFailed, $this->condition('payment.status', 'equals', 'failed'), $this->condition('document.paid', 'is_false'))->published_version_id);
        $this->assertNotNull($this->workflow(WorkflowTriggerType::AppointmentScheduled, $this->condition('appointment.status', 'equals', 'scheduled'))->published_version_id);
        $this->assertNotNull($this->workflow(WorkflowTriggerType::ContactCreated, $this->condition('opportunity.stage', 'equals', (string) $this->stages[1]->id))->published_version_id);
    }

    public function test_the_test_panel_says_an_event_condition_reads_as_not_set_for_a_test_contact(): void
    {
        $event = $this->workflow(WorkflowTriggerType::DocumentSent, $this->condition('document.signed', 'is_true'));
        $plain = $this->workflow(WorkflowTriggerType::ManualEnrollment, $this->condition('contact.subscribed', 'is_true'));
        $simulator = app(\App\Library\Automation\Workflow\WorkflowSimulator::class);

        $branchOf = fn (AutomationWorkflow $workflow): array => collect($simulator->simulate(
            \App\Models\AutomationWorkflowVersion::query()->findOrFail($workflow->fresh()->published_version_id),
            $this->world['contact'],
        )['steps'])->firstWhere('did', 'branched');

        $this->assertStringContainsString('count as not set', (string) $branchOf($event)['detail']);
        $this->assertStringNotContainsString('not set', (string) ($branchOf($plain)['detail'] ?? ''), 'A plain contact condition carries no such note.');
    }
}
