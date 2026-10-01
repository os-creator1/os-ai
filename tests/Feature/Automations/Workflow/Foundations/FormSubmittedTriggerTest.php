<?php

namespace Tests\Feature\Automations\Workflow\Foundations;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Forms\FormSubmissionRecorded;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Triggers\FormSubmittedTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Listeners\Automation\Workflow\EnrollFromFormSubmission;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSession;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Automations x Forms — "A form is submitted".
 *
 * Submissions are made through the real FormSubmissionService, so the one
 * FormSubmissionRecorded event is the one production emits, after the
 * submission's own commit.
 */
class FormSubmittedTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFormsFixtures;
    use BuildsFoundationWorkflows;

    private Business $business;

    private BusinessLocation $downtown;

    /** @var list<FormSubmissionRecorded> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [, $this->business] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);

        Event::listen(FormSubmissionRecorded::class, function (FormSubmissionRecorded $event): void {
            $this->events[] = $event;
        });
    }

    private function submit(FormDeployment $deployment, array $answers = []): \App\Library\Forms\FormSubmissionResult
    {
        return app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, $answers));
    }

    private function source(): FormSubmittedTriggerSource
    {
        return app(TriggerSourceRegistry::class)->for(WorkflowTriggerType::FormSubmitted);
    }

    // =================================================================
    // 13 + 16. A final submission enrolls once, with its identity
    // =================================================================

    public function test_a_final_submission_enrolls_once_and_preserves_every_identity(): void
    {
        [$form, $deployment] = $this->liveForm($this->business, $this->downtown, ['create_opportunity' => true]);
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);

        $result = $this->submit($deployment);
        $submission = $result->submission;

        $this->assertSame(1, $this->enrollmentCount($workflow));
        $enrollment = AutomationEnrollment::query()->sole();

        $this->assertSame(WorkflowTriggerType::FormSubmitted, $enrollment->trigger_type);
        $this->assertSame('form_submission:' . $submission->uid, $enrollment->trigger_occurrence_key);
        $this->assertSame((int) $submission->contact_id, (int) $enrollment->contact_id);
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);

        $context = $this->source()->contextForEnrollment($enrollment);

        $this->assertSame([
            'business_id' => (int) $this->business->id,
            'location_id' => (int) $this->downtown->id,
            'form_id' => (int) $form->id,
            'form_version_id' => (int) $submission->form_version_id,
            'submission_id' => (int) $submission->id,
            'contact_id' => (int) $submission->contact_id,
            'opportunity_id' => (int) $submission->crm_opportunity_id,
            'occurrence_key' => 'form_submission:' . $submission->uid,
        ], $context->toArray());
        $this->assertNotNull($context->opportunityId, 'The Opportunity the submission linked is part of the identity.');
        $this->assertArrayNotHasKey('values', $context->toArray(), 'No answer is copied into the trigger facts.');
    }

    public function test_the_trigger_keeps_the_version_the_visitor_submitted_after_the_form_changes(): void
    {
        [$form, $deployment] = $this->liveForm($this->business, $this->downtown);
        $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);

        $submission = $this->submit($deployment)->submission;
        $submittedVersion = (int) $submission->form_version_id;

        // The form is edited afterwards: a new immutable version becomes current.
        app(FormManager::class)->update($this->business, $form->fresh(), $this->leadFormInput(['name' => 'Renamed', 'fields' => array_merge(
            $this->leadFormInput()['fields'],
            [['label' => 'Extra', 'type' => 'text', 'required' => false]],
        )]));
        $this->assertNotSame($submittedVersion, (int) $form->fresh()->currentVersion()->id, 'The edit really created a new version.');

        $context = $this->source()->contextForEnrollment(AutomationEnrollment::query()->sole());

        $this->assertSame($submittedVersion, $context->formVersionId, 'The enrollment still names the version that was submitted.');
        $this->assertSame((int) $submission->id, $context->submissionId);
    }

    // =================================================================
    // 14. Intermediate questionnaire pages never enroll
    // =================================================================

    public function test_intermediate_questionnaire_pages_never_enroll_and_the_final_page_does_once(): void
    {
        $form = $this->makeQuestionnaire($this->business);
        $deployment = $this->deploy($this->business, $form, $this->downtown);
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);
        $service = app(FormSubmissionService::class);
        $token = FormOperationToken::issue($deployment);

        $service->submit($deployment->uid, $this->stepInput($token, 'page_1'));
        $service->submit($deployment->uid, $this->stepInput($token, 'page_2'));

        $this->assertSame(0, $this->enrollmentCount($workflow), 'Moving between pages is not a submission.');
        $this->assertSame([], $this->events);
        $this->assertSame(0, FormSubmission::query()->count());
        $this->assertSame(1, FormSession::query()->count(), 'The held answers are a session, not a submission.');

        $final = $service->submit($deployment->uid, $this->stepInput($token, 'page_3'));

        $this->assertTrue($final->isFinal());
        $this->assertSame(1, $this->enrollmentCount($workflow));
        $this->assertSame(1, FormSubmission::query()->count());
    }

    public function test_the_trigger_never_writes_the_session_or_the_submission(): void
    {
        $form = $this->makeQuestionnaire($this->business);
        $deployment = $this->deploy($this->business, $form, $this->downtown);
        $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);
        $service = app(FormSubmissionService::class);
        $token = FormOperationToken::issue($deployment);

        foreach (['page_1', 'page_2'] as $page) {
            $service->submit($deployment->uid, $this->stepInput($token, $page));
        }

        $service->submit($deployment->uid, $this->stepInput($token, 'page_3'));

        $this->assertSame(1, AutomationEnrollment::query()->count());

        // The listener ran inside that submit; run it again and prove it changes nothing.
        $snapshot = fn () => [DB::table('form_submissions')->get()->all(), DB::table('form_sessions')->get()->all()];
        $before = $snapshot();
        $this->finishJourneys();
        app(EnrollFromFormSubmission::class)->handle($this->events[0]);
        $this->assertEquals($before, $snapshot(), 'Handling the event writes neither the FormSubmission nor the FormSession.');

        $source = file_get_contents(base_path('app/Library/Automation/Workflow/Triggers/FormSubmittedTriggerSource.php'));
        // Code only: the docblock names the session to say it is never written.
        $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);
        foreach (['FormSession', 'form_sessions', '->update(', '->save(', '->delete(', 'DB::table(\'form_submissions\')->update'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    // =================================================================
    // 15. Duplicate delivery converges
    // =================================================================

    public function test_duplicate_delivery_of_the_final_event_converges(): void
    {
        [, $deployment] = $this->liveForm($this->business, $this->downtown);
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);

        $input = $this->submitInput($deployment);
        $first = app(FormSubmissionService::class)->submit($deployment->uid, $input);
        $this->assertCount(1, $this->events);

        // The same operation token, posted again: the Forms domain answers with the original.
        $replay = app(FormSubmissionService::class)->submit($deployment->uid, $input);
        $this->assertTrue($replay->replayed);
        $this->assertSame((int) $first->submission->id, (int) $replay->submission->id);
        $this->assertCount(1, $this->events, 'A replayed submission emits no second event.');

        // And the one event delivered again and again (a retried listener).
        $this->finishJourneys();
        $listener = app(EnrollFromFormSubmission::class);
        $listener->handle($this->events[0]);
        $listener->handle($this->events[0]);

        $this->assertSame(1, $this->enrollmentCount($workflow));
        $this->assertSame(1, FormSubmission::query()->count());
    }

    // =================================================================
    // Filters and tenancy
    // =================================================================

    public function test_the_form_filter_narrows_to_one_form(): void
    {
        [$formA, $deploymentA] = $this->liveForm($this->business, $this->downtown, ['name' => 'Form A']);
        [$formB, $deploymentB] = $this->liveForm($this->business, $this->downtown, ['name' => 'Form B']);

        $onlyB = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted, ['form_id' => $formB->id]);
        $any = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);

        $this->submit($deploymentA, ['phone' => '+1 (415) 555-0001']);

        $this->assertSame(0, $this->enrollmentCount($onlyB));
        $this->assertSame(1, $this->enrollmentCount($any));

        $this->finishJourneys();
        $this->submit($deploymentB, ['phone' => '+1 (415) 555-0002']);

        $this->assertSame(1, $this->enrollmentCount($onlyB));
    }

    public function test_a_business_a_submission_cannot_enroll_a_business_b_workflow(): void
    {
        [$ownerB, $businessB] = $this->formsTenant(name: 'Other Studio');
        $workflowB = $this->triggerWorkflow($businessB, WorkflowTriggerType::FormSubmitted);
        $workflowA = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);
        [, $deployment] = $this->liveForm($this->business, $this->downtown);

        $this->submit($deployment);

        $this->assertSame(1, $this->enrollmentCount($workflowA));
        $this->assertSame(0, $this->enrollmentCount($workflowB));
    }

    public function test_forged_events_are_refused_when_they_disagree_with_the_persisted_submission(): void
    {
        $uptown = $this->formsLocation($this->business, 'Uptown');
        [, $deployment] = $this->liveForm($this->business, $this->downtown);
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted);
        $this->submit($deployment);
        $real = $this->events[0];
        $this->finishJourneys();

        [, $businessB] = $this->formsTenant(name: 'Other Studio');
        $workflowB = $this->triggerWorkflow($businessB, WorkflowTriggerType::FormSubmitted);

        $listener = app(EnrollFromFormSubmission::class);
        $forge = fn (array $override) => new FormSubmissionRecorded(...array_merge([
            'businessId' => $real->businessId, 'locationId' => $real->locationId, 'formId' => $real->formId,
            'formVersionId' => $real->formVersionId, 'submissionId' => $real->submissionId, 'contactId' => $real->contactId,
            'opportunityId' => $real->opportunityId, 'contactResolution' => $real->contactResolution,
            'occurrenceKey' => $real->occurrenceKey,
        ], $override));

        // Location B's id on Location A's submission.
        $listener->handle($forge(['locationId' => (int) $uptown->id]));
        // Another Business's id on this submission (and the reverse: its workflow listening).
        $listener->handle($forge(['businessId' => (int) $businessB->id]));
        // A different Form / version than the row records.
        $listener->handle($forge(['formId' => $real->formId + 999]));
        $listener->handle($forge(['formVersionId' => $real->formVersionId + 999]));
        // A different Contact than the submission linked.
        $listener->handle($forge(['contactId' => $real->contactId + 999]));
        // An occurrence key that is not the submission's.
        $listener->handle($forge(['occurrenceKey' => 'form_submission:forged']));
        // A submission that does not exist.
        $listener->handle($forge(['submissionId' => $real->submissionId + 999]));

        $this->assertSame(1, $this->enrollmentCount($workflow), 'Only the genuine event ever enrolled.');
        $this->assertSame(0, $this->enrollmentCount($workflowB));
    }

    public function test_a_foreign_form_cannot_be_published_into_the_filter(): void
    {
        [, $businessB] = $this->formsTenant(name: 'Other Studio');
        $foreign = $this->makeForm($businessB, ['name' => 'Theirs']);

        try {
            $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted, ['form_id' => $foreign->id]);
            $this->fail('A foreign form id must not publish.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $exception);
        }
    }
}
