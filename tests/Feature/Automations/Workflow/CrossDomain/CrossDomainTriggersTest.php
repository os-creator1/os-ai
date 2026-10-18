<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\DocumentPaymentFailed;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Triggers\TriggerCause;
use App\Library\Documents\DocumentManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\BusinessDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\TestCase;

/**
 * Automations x Documents / Payments / Forms — the five new triggers.
 *
 * Each one is the owning domain's own durable after-commit event, handed to a
 * trigger source that re-reads every fact from the domain's own rows. These tests
 * pin what the brief asks of every trigger: Business-scoped, Location derived from
 * the fact, a deterministic occurrence key, no duplicate enrollment on replay, and
 * the workflow's scope obeyed.
 */
class CrossDomainTriggersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
        Notification::fake();
    }

    private function listening(WorkflowTriggerType $type, array $config = []): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->world['business'], $type, $config);
    }

    /** A document sent through the REAL manager, so DocumentSent is the one production emits. */
    private function sentDocument(array $overrides = []): BusinessDocument
    {
        return app(DocumentManager::class)->send($this->draftDocument($this->world, $overrides));
    }

    private function enrollments(AutomationWorkflow $workflow): \Illuminate\Support\Collection
    {
        return AutomationEnrollment::query()->where('workflow_id', $workflow->id)->get();
    }

    private function signature(BusinessDocument $document): int
    {
        $versionId = (int) $document->current_version_id;
        DB::table('business_documents')->where('id', $document->id)->update(['status' => 'signed', 'signed_at' => now()]);

        return $this->insertSignature((int) $document->id, $versionId);
    }

    private function payment(BusinessDocument $document, string $status): int
    {
        $itemId = (int) DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $document->current_version_id)->value('id');
        $connectionId = (int) DB::table('business_stripe_connections')->where('business_id', $document->business_id)->value('id');

        return $this->insertPayment((int) $document->business_id, (int) $document->id, $itemId, $connectionId, [
            'status' => $status,
            'succeeded_at' => $status === 'succeeded' ? now() : null,
        ]);
    }

    // =================================================================
    // document_sent
    // =================================================================

    public function test_a_sent_document_enrolls_the_documents_contact_at_the_documents_location_once(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::DocumentSent);

        $document = $this->sentDocument();

        $enrollment = $this->enrollments($workflow)->sole();
        $this->assertSame((int) $document->contact_id, (int) $enrollment->contact_id);
        $this->assertSame((int) $document->business_location_id, (int) $enrollment->business_location_id, 'The Location is the document\'s own.');
        $this->assertSame('document_version_sent:' . $document->current_version_id, $enrollment->trigger_occurrence_key);
        $this->assertSame(WorkflowTriggerType::DocumentSent, $enrollment->trigger_type);
    }

    public function test_a_replayed_document_sent_event_enrolls_nobody_twice(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::DocumentSent);
        $document = $this->sentDocument();
        $this->finishJourneys();

        $event = new DocumentSent((int) $document->id, (int) $document->current_version_id, 1, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id);
        event($event);
        event($event);

        $this->assertSame(1, $this->enrollments($workflow)->count(), 'The occurrence key is the one issued version.');
    }

    public function test_the_document_kind_filter_narrows_every_document_trigger(): void
    {
        $invoicesOnly = $this->listening(WorkflowTriggerType::DocumentSent, ['document_kind' => 'invoice']);
        $proposalsOnly = $this->listening(WorkflowTriggerType::DocumentSent, ['document_kind' => 'proposal']);
        $any = $this->listening(WorkflowTriggerType::DocumentSent);

        $this->sentDocument(['kind' => 'proposal']);

        $this->assertSame(0, $this->enrollments($invoicesOnly)->count());
        $this->assertSame(1, $this->enrollments($proposalsOnly)->count());
        $this->assertSame(1, $this->enrollments($any)->count());
    }

    public function test_a_workflow_limited_to_other_locations_does_not_take_the_document(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $midtown = $this->xLocation($this->world['business'], 'Midtown');
        $mainOnly = $this->listening(WorkflowTriggerType::DocumentSent, ['business_location_id' => $this->world['location']->id]);
        $uptownOnly = $this->listening(WorkflowTriggerType::DocumentSent, ['business_location_id' => $uptown->id]);
        $selected = $this->listening(WorkflowTriggerType::DocumentSent, ['scope_mode' => 'selected', 'business_location_ids' => [$this->world['location']->id, $uptown->id]]);
        $elsewhere = $this->listening(WorkflowTriggerType::DocumentSent, ['scope_mode' => 'selected', 'business_location_ids' => [$uptown->id, $midtown->id]]);
        $wide = $this->listening(WorkflowTriggerType::DocumentSent);

        $this->sentDocument();

        $this->assertSame([1, 0, 1, 0, 1], array_map(fn ($w) => $this->enrollments($w)->count(), [$mainOnly, $uptownOnly, $selected, $elsewhere, $wide]));
    }

    public function test_another_businesss_document_never_enrolls_this_businesss_workflow(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::DocumentSent);

        // A document of a second Business, announced as if it were ours.
        $other = $this->sendableTenant('Other Studio');
        $theirs = app(DocumentManager::class)->send($this->draftDocument($other));
        $this->finishJourneys();
        AutomationEnrollment::query()->delete();

        event(new DocumentSent((int) $theirs->id, (int) $theirs->current_version_id, 1, (int) $this->world['business']->id, (int) $theirs->business_location_id, (int) $theirs->contact_id));

        $this->assertSame(0, $this->enrollments($workflow)->count());
    }

    public function test_a_version_that_is_not_the_documents_enrolls_nobody(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::DocumentSent);
        $document = $this->sentDocument();
        AutomationEnrollment::query()->delete();

        $other = app(DocumentManager::class)->send($this->draftDocument($this->world));
        AutomationEnrollment::query()->delete();

        // The event names this document, but a version that belongs to ANOTHER document.
        event(new DocumentSent((int) $document->id, (int) $other->current_version_id, 1, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id));

        $this->assertSame(0, $this->enrollments($workflow)->count());
    }

    // =================================================================
    // document_signed
    // =================================================================

    public function test_a_signature_enrolls_once_under_the_signature_rows_key(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::DocumentSigned);
        $document = $this->sentDocument();
        $signatureId = $this->signature($document);

        $event = new DocumentSigned((int) $document->id, (int) $document->current_version_id, $signatureId, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id);
        event($event);
        $this->finishJourneys();
        event($event);

        $enrollment = $this->enrollments($workflow)->sole();
        $this->assertSame('document_signature:' . $signatureId, $enrollment->trigger_occurrence_key);
        $this->assertSame((int) $document->business_location_id, (int) $enrollment->business_location_id);
    }

    public function test_a_signature_that_does_not_belong_to_the_document_enrolls_nobody(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::DocumentSigned);
        $document = $this->sentDocument();
        $other = $this->sentDocument();
        $foreignSignature = $this->signature($other);

        event(new DocumentSigned((int) $document->id, (int) $document->current_version_id, $foreignSignature, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id));

        $this->assertSame(0, $this->enrollments($workflow)->count());
    }

    // =================================================================
    // payment_succeeded / payment_failed
    // =================================================================

    public function test_a_successful_payment_enrolls_once_per_payment_row(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::PaymentSucceeded);
        $document = $this->sentDocument();
        $paymentId = $this->payment($document, 'succeeded');

        $event = new DocumentPaymentSucceeded((int) $document->id, $paymentId, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id);
        event($event);
        $this->finishJourneys();
        event($event);

        $enrollment = $this->enrollments($workflow)->sole();
        $this->assertSame('document_payment_succeeded:' . $paymentId, $enrollment->trigger_occurrence_key);
        $this->assertSame((int) $document->business_location_id, (int) $enrollment->business_location_id);
    }

    public function test_a_success_event_for_a_payment_that_did_not_succeed_enrolls_nobody(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::PaymentSucceeded);
        $document = $this->sentDocument();
        $paymentId = $this->payment($document, 'created');

        event(new DocumentPaymentSucceeded((int) $document->id, $paymentId, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id));

        $this->assertSame(0, $this->enrollments($workflow)->count(), 'Only a row that really reached succeeded can have emitted this.');
    }

    public function test_a_failed_payment_enrolls_under_its_own_key_and_a_later_success_is_a_different_occurrence(): void
    {
        $failed = $this->listening(WorkflowTriggerType::PaymentFailed);
        $succeeded = $this->listening(WorkflowTriggerType::PaymentSucceeded);
        $document = $this->sentDocument();
        $paymentId = $this->payment($document, 'failed');

        event(new DocumentPaymentFailed((int) $document->id, $paymentId, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id));

        $this->assertSame('document_payment_failed:' . $paymentId, $this->enrollments($failed)->sole()->trigger_occurrence_key);
        $this->assertSame(0, $this->enrollments($succeeded)->count());

        // The customer retries and the SAME row succeeds: its own occurrence.
        DB::table('business_document_payments')->where('id', $paymentId)->update(['status' => 'succeeded', 'succeeded_at' => now()]);
        event(new DocumentPaymentSucceeded((int) $document->id, $paymentId, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id));

        $this->assertSame('document_payment_succeeded:' . $paymentId, $this->enrollments($succeeded)->sole()->trigger_occurrence_key);
    }

    public function test_a_payment_of_another_document_is_no_fact(): void
    {
        $workflow = $this->listening(WorkflowTriggerType::PaymentSucceeded);
        $document = $this->sentDocument();
        $other = $this->sentDocument();
        $paymentId = $this->payment($other, 'succeeded');

        event(new DocumentPaymentSucceeded((int) $document->id, $paymentId, (int) $document->business_id, (int) $document->business_location_id, (int) $document->contact_id));

        $this->assertSame(0, $this->enrollments($workflow)->count());
    }

    // =================================================================
    // Loop prevention: a document an automation sent never restarts its own workflow
    // =================================================================

    public function test_a_document_an_automation_sent_does_not_restart_the_workflow_that_sent_it(): void
    {
        $producer = $this->listening(WorkflowTriggerType::DocumentSent);
        $other = $this->listening(WorkflowTriggerType::DocumentSent);

        // The producing workflow has a journey with a claimed step run, which is what an
        // automation-made send names as its origin.
        $contact = $this->xContact($this->world, $this->world['location'], '14155559001');
        $journey = $this->xAdvance($this->xEnroll($producer, $contact, 'seed'));
        $stepRunId = (int) DB::table('automation_step_runs')->where('enrollment_id', $journey->id)->value('id');
        $this->assertGreaterThan(0, $stepRunId);
        DB::table('automation_enrollments')->where('id', $journey->id)->update(['causation_depth' => 1]);

        $document = $this->draftDocument($this->world);
        app(DocumentManager::class)->send($document, origin: TriggerCause::originFor($stepRunId));

        $this->assertSame(0, $this->enrollments($producer)->where('id', '!=', $journey->id)->count(), 'The workflow never re-triggers off its own output.');
        $enrolled = $this->enrollments($other)->sole();
        $this->assertSame(2, (int) $enrolled->causation_depth, 'Any other workflow runs one link deeper than its producer.');
    }

    // =================================================================
    // questionnaire_submitted
    // =================================================================

    public function test_a_questionnaire_fires_both_form_triggers_and_a_one_page_form_only_the_first(): void
    {
        $questionnaireWorkflow = $this->listening(WorkflowTriggerType::QuestionnaireSubmitted);
        $formWorkflow = $this->listening(WorkflowTriggerType::FormSubmitted);
        $location = $this->world['location'];
        $this->formsPipeline($this->world['business']);

        // A one-page form.
        [, $deployment] = $this->liveForm($this->world['business'], $location);
        app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment));

        $this->assertSame(0, $this->enrollments($questionnaireWorkflow)->count(), 'A one-page form is not a questionnaire.');
        $this->assertSame(1, $this->enrollments($formWorkflow)->count());

        // A multi-page questionnaire, submitted to its final page.
        $this->finishJourneys();
        $form = $this->makeQuestionnaire($this->world['business']);
        $questionnaire = $this->deploy($this->world['business'], $form, $location);
        $token = FormOperationToken::issue($questionnaire);
        $service = app(FormSubmissionService::class);
        $service->submit($questionnaire->uid, $this->stepInput($token, 'page_1'));
        $service->submit($questionnaire->uid, $this->stepInput($token, 'page_2'));
        $service->submit($questionnaire->uid, $this->stepInput($token, 'page_3'));

        $this->assertSame(1, $this->enrollments($questionnaireWorkflow)->count(), 'The final page of a questionnaire fires it once.');
        $this->assertSame(2, $this->enrollments($formWorkflow)->count(), 'A questionnaire is also a form submission.');

        $enrollment = $this->enrollments($questionnaireWorkflow)->sole();
        $this->assertStringStartsWith('form_submission:', $enrollment->trigger_occurrence_key);
        $this->assertSame((int) $location->id, (int) $enrollment->business_location_id);
    }

    public function test_the_questionnaire_filter_narrows_to_one_questionnaire(): void
    {
        $location = $this->world['location'];
        $this->formsPipeline($this->world['business']);
        $wanted = $this->makeQuestionnaire($this->world['business']);
        $wantedDeployment = $this->deploy($this->world['business'], $wanted, $location);
        $other = $this->makeQuestionnaire($this->world['business'], ['name' => 'Other questionnaire']);
        $otherDeployment = $this->deploy($this->world['business'], $other, $location);

        $filtered = $this->listening(WorkflowTriggerType::QuestionnaireSubmitted, ['form_id' => $wanted->id]);

        $service = app(FormSubmissionService::class);
        $token = FormOperationToken::issue($otherDeployment);
        $service->submit($otherDeployment->uid, $this->stepInput($token, 'page_1'));
        $service->submit($otherDeployment->uid, $this->stepInput($token, 'page_2'));
        $service->submit($otherDeployment->uid, $this->stepInput($token, 'page_3'));
        $this->assertSame(0, $this->enrollments($filtered)->count());

        $this->finishJourneys();
        $token = FormOperationToken::issue($wantedDeployment);
        $service->submit($wantedDeployment->uid, $this->stepInput($token, 'page_1'));
        $service->submit($wantedDeployment->uid, $this->stepInput($token, 'page_2'));
        $service->submit($wantedDeployment->uid, $this->stepInput($token, 'page_3'));
        $this->assertSame(1, $this->enrollments($filtered)->count());
    }
}
