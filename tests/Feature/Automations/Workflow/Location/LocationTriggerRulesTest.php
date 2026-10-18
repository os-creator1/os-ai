<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Crm\ContactTagAdded;
use App\Events\Forms\FormSubmissionRecorded;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\TagManager;
use App\Library\Forms\FormSubmissionService;
use App\Listeners\Automation\Workflow\EnrollFromContactTagEvent;
use App\Listeners\Automation\Workflow\EnrollFromFormSubmission;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — which facts may enroll a bound workflow.
 *
 * Every enrollment is produced through the real domain: TagManager, the Forms
 * submission service, the CRM service. The Location a trigger offers is the
 * FACT's own, as that domain recorded it.
 */
class LocationTriggerRulesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use CreatesFormsFixtures;
    use BuildsFoundationWorkflows;

    private Business $business;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [, $this->business] = $this->crmTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
    }

    private function bound(WorkflowTriggerType $type, BusinessLocation $location, array $extra = []): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->business, $type, ['business_location_id' => $location->id] + $extra);
    }

    private function wide(WorkflowTriggerType $type): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->business, $type);
    }

    private function contactAt(?BusinessLocation $location): Contacts
    {
        $contact = $this->crmContact($this->business);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        return $contact->fresh();
    }

    private function pinned(AutomationWorkflow $workflow): ?int
    {
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->first();

        return $enrollment?->business_location_id === null ? null : (int) $enrollment->business_location_id;
    }

    // =================================================================
    // 5-8. Contact tags
    // =================================================================

    public function test_a_tag_event_enrolls_only_the_workflow_bound_to_the_contacts_location(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $forDowntown = $this->bound(WorkflowTriggerType::ContactTagAdded, $this->downtown);
        $forUptown = $this->bound(WorkflowTriggerType::ContactTagAdded, $this->uptown);

        app(TagManager::class)->attachTag($this->business, $this->contactAt($this->downtown), $tag);

        $this->assertSame(1, $this->enrollmentCount($forDowntown));
        $this->assertSame((int) $this->downtown->id, $this->pinned($forDowntown));
        $this->assertSame(0, $this->enrollmentCount($forUptown), 'The same event must not reach the other Location\'s workflow.');
    }

    public function test_a_contact_with_no_location_enrolls_no_bound_workflow_but_every_business_wide_one(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $bound = $this->bound(WorkflowTriggerType::ContactTagAdded, $this->downtown);
        $wide = $this->wide(WorkflowTriggerType::ContactTagAdded);

        app(TagManager::class)->attachTag($this->business, $this->contactAt(null), $tag);

        $this->assertSame(0, $this->enrollmentCount($bound), 'A null Location never enrolls a Location-bound workflow.');
        $this->assertSame(1, $this->enrollmentCount($wide));
        $this->assertNull($this->pinned($wide), 'And nothing is invented for it.');
    }

    public function test_a_business_wide_tag_workflow_pins_the_contacts_location(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $wide = $this->wide(WorkflowTriggerType::ContactTagRemoved);
        $contact = $this->contactAt($this->uptown);

        app(TagManager::class)->attachTag($this->business, $contact, $tag);
        app(TagManager::class)->detachTag($this->business, $contact, $tag);

        $this->assertSame(1, $this->enrollmentCount($wide));
        $this->assertSame((int) $this->uptown->id, $this->pinned($wide));
    }

    public function test_the_tag_triggers_location_is_the_one_captured_at_the_mutation(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'VIP');
        $contact = $this->contactAt($this->downtown);
        $events = [];
        Event::listen(ContactTagAdded::class, function (ContactTagAdded $event) use (&$events): void {
            $events[] = $event;
        });

        app(TagManager::class)->attachTag($this->business, $contact, $tag);
        $this->assertSame((int) $this->downtown->id, $events[0]->locationId);

        // The contact moves; a workflow published afterwards sees the SAME fact.
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $this->uptown->id]);
        $forUptown = $this->bound(WorkflowTriggerType::ContactTagAdded, $this->uptown);
        $forDowntown = $this->bound(WorkflowTriggerType::ContactTagAdded, $this->downtown);
        $this->finishJourneys();

        app(EnrollFromContactTagEvent::class)->handle($events[0]);

        $this->assertSame(0, $this->enrollmentCount($forUptown), 'Where the Contact is now is not where the fact happened.');
        $this->assertSame(1, $this->enrollmentCount($forDowntown));
    }

    // =================================================================
    // 9-11. Forms
    // =================================================================

    private function submitAt(BusinessLocation $location, string $phone): \App\Models\FormSubmission
    {
        [, $deployment] = $this->liveForm($this->business, $location, ['name' => 'Form ' . $location->name . $phone]);

        return app(FormSubmissionService::class)->submit(
            $deployment->uid,
            $this->submitInput($deployment, ['phone' => $phone]),
        )->submission;
    }

    public function test_a_submission_enrolls_only_the_workflow_bound_to_its_location(): void
    {
        $forDowntown = $this->bound(WorkflowTriggerType::FormSubmitted, $this->downtown);
        $forUptown = $this->bound(WorkflowTriggerType::FormSubmitted, $this->uptown);
        $wide = $this->wide(WorkflowTriggerType::FormSubmitted);

        $submission = $this->submitAt($this->downtown, '+1 (415) 555-0101');

        $this->assertSame(1, $this->enrollmentCount($forDowntown));
        $this->assertSame(0, $this->enrollmentCount($forUptown));
        $this->assertSame(1, $this->enrollmentCount($wide));
        $this->assertSame((int) $submission->business_location_id, (int) $this->downtown->id);
        $this->assertSame((int) $this->downtown->id, $this->pinned($forDowntown));
        $this->assertSame((int) $this->downtown->id, $this->pinned($wide));
    }

    public function test_the_immutable_submissions_location_is_used_not_the_contacts_current_one(): void
    {
        $events = [];
        Event::listen(FormSubmissionRecorded::class, function (FormSubmissionRecorded $event) use (&$events): void {
            $events[] = $event;
        });
        $submission = $this->submitAt($this->downtown, '+1 (415) 555-0102');

        // The Forms-created contact is moved to Uptown afterwards; the submission row never moves.
        DB::table('contacts')->where('id', $submission->contact_id)->update(['location_id' => $this->uptown->id]);
        $forDowntown = $this->bound(WorkflowTriggerType::FormSubmitted, $this->downtown);
        $forUptown = $this->bound(WorkflowTriggerType::FormSubmitted, $this->uptown);

        app(EnrollFromFormSubmission::class)->handle($events[0]);

        $this->assertSame(1, $this->enrollmentCount($forDowntown), 'The submission happened at Downtown.');
        $this->assertSame(0, $this->enrollmentCount($forUptown));
    }

    // =================================================================
    // Existing triggers audited
    // =================================================================

    public function test_a_crm_deal_enrolls_only_the_workflow_bound_to_the_deals_location(): void
    {
        $pipeline = $this->standardPipeline($this->business);
        $forDowntown = $this->bound(WorkflowTriggerType::OpportunityCreated, $this->downtown);
        $forUptown = $this->bound(WorkflowTriggerType::OpportunityCreated, $this->uptown);
        $wide = $this->wide(WorkflowTriggerType::OpportunityCreated);
        $contact = $this->contactAt($this->downtown);

        $deal = app(CrmOpportunityService::class)->createAtLocation(
            (int) $this->business->id,
            (int) $this->downtown->id,
            (int) $pipeline->id,
            (int) $contact->id,
            'Kitchen',
        );

        $this->assertSame((int) $this->downtown->id, (int) $deal->location_id);
        $this->assertSame(1, $this->enrollmentCount($forDowntown));
        $this->assertSame(0, $this->enrollmentCount($forUptown));
        $this->assertSame((int) $this->downtown->id, $this->pinned($wide));
    }

    public function test_a_deal_with_no_location_enrolls_no_bound_workflow(): void
    {
        $pipeline = $this->standardPipeline($this->business);
        $bound = $this->bound(WorkflowTriggerType::OpportunityCreated, $this->downtown);
        $wide = $this->wide(WorkflowTriggerType::OpportunityCreated);

        $deal = app(CrmOpportunityService::class)->create($this->business, $pipeline, $this->contactAt(null), 'Unplaced');
        $this->assertNull($deal->location_id, 'The fixture really has no Location to offer.');

        $this->assertSame(0, $this->enrollmentCount($bound));
        $this->assertSame(1, $this->enrollmentCount($wide));
    }

    public function test_a_contact_created_trigger_uses_the_new_contacts_own_location(): void
    {
        $forDowntown = $this->bound(WorkflowTriggerType::ContactCreated, $this->downtown);
        $forUptown = $this->bound(WorkflowTriggerType::ContactCreated, $this->uptown);
        $wide = $this->wide(WorkflowTriggerType::ContactCreated);
        $contact = $this->contactAt($this->downtown);

        app(\App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource::class)
            ->handleContactCreated($contact, \App\Enums\Automation\Workflow\ContactCreationSource::Manual);

        $this->assertSame(1, $this->enrollmentCount($forDowntown));
        $this->assertSame(0, $this->enrollmentCount($forUptown));
        $this->assertSame((int) $this->downtown->id, $this->pinned($wide));

        // A contact the CRM never placed enrolls no bound workflow.
        app(\App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource::class)
            ->handleContactCreated($this->contactAt(null), \App\Enums\Automation\Workflow\ContactCreationSource::Manual);

        $this->assertSame(1, $this->enrollmentCount($forDowntown));
        $this->assertSame(2, $this->enrollmentCount($wide));
    }

    public function test_every_enrollment_call_that_forgets_the_location_fails_closed_for_a_bound_workflow(): void
    {
        $bound = $this->bound(WorkflowTriggerType::ManualEnrollment, $this->downtown);
        $contact = $this->contactAt($this->downtown);

        // The 3-argument form every trigger used before Location scope existed.
        $this->assertNull(app(EnrollmentService::class)->enroll($bound, $contact, 'legacy-call'));
        $this->assertSame(0, $this->enrollmentCount($bound));

        $this->assertNotNull(app(EnrollmentService::class)->enroll($bound, $contact, 'proper-call', 0, (int) $this->downtown->id));
    }
}
