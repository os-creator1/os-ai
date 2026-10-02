<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\TestCase;

/**
 * Automations Location run-scope — Send SMS and Update contact field after a
 * Contact leaves a bound journey's Location.
 *
 * Contract: a Location-bound journey never mutates or texts a Contact under a
 * scope the Contact has left, never re-scopes to the Contact's new Location, and
 * never falls back. Business-wide journeys keep their existing behaviour.
 */
class LocationActionDriftTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsFoundationWorkflows;
    use BuildsActionWorkflows;

    private Business $business;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private ContactGroups $group;

    private ContactGroupFields $field;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [, $this->business] = $this->entitledTenant();
        $this->sendableChannel($this->business);
        $this->downtown = BusinessLocation::create(['business_id' => $this->business->id, 'name' => 'Downtown', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $this->uptown = BusinessLocation::create(['business_id' => $this->business->id, 'name' => 'Uptown', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $this->group = $this->contactGroup($this->business, 'Clients');
        $this->field = $this->textField($this->group, 'STATUS_NOTE');
    }

    private function contactAt(BusinessLocation $location, string $phone): Contacts
    {
        $contact = $this->contact($this->business, $this->group, $phone);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);

        return $contact->fresh();
    }

    private function move(Contacts $contact, BusinessLocation $to): void
    {
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $to->id]);
    }

    /** A group-scoped workflow (the field action needs one), optionally bound. */
    private function journey(array $steps, ?BusinessLocation $bound): AutomationWorkflow
    {
        return $this->triggerWorkflow(
            $this->business,
            WorkflowTriggerType::ContactCreated,
            ['contact_group_id' => $this->group->id] + ($bound === null ? [] : ['business_location_id' => $bound->id]),
            $steps,
        );
    }

    private function drive(AutomationWorkflow $workflow, Contacts $contact, BusinessLocation $pinned, bool $moveFirst = true, ?BusinessLocation $to = null): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'k-' . $contact->id, 0, (int) $pinned->id);
        $this->assertNotNull($enrollment);

        if ($moveFirst) {
            $this->move($contact, $to ?? $this->uptown);
        }

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        return $enrollment->fresh();
    }

    private function step(AutomationEnrollment $enrollment, string $type): AutomationStepRun
    {
        return AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', $type)->sole();
    }

    private function note(Contacts $contact): ?string
    {
        return DB::table('contacts_custom_field')->where('contact_id', $contact->id)->where('field_id', $this->field->id)->value('value');
    }

    // =================================================================
    // Update contact field
    // =================================================================

    public function test_a_bound_journey_does_not_write_to_a_contact_who_moved_away(): void
    {
        $workflow = $this->journey([$this->updateFieldStep((int) $this->field->id, 'vip'), $this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown, '12025552001');

        $enrollment = $this->drive($workflow, $contact, $this->downtown);

        $this->assertNull($this->note($contact), 'Nothing is written under a scope the Contact has left.');
        $step = $this->step($enrollment, 'update_contact_field');
        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame((int) $this->downtown->id, (int) $enrollment->business_location_id, 'And the run did not re-scope to the Contact\'s new Location.');
    }

    public function test_a_bound_journey_still_writes_to_a_contact_who_is_at_its_location(): void
    {
        $workflow = $this->journey([$this->updateFieldStep((int) $this->field->id, 'vip'), $this->endStep()], $this->downtown);
        $contact = $this->contactAt($this->downtown, '12025552002');

        $this->drive($workflow, $contact, $this->downtown, moveFirst: false);

        $this->assertSame('vip', $this->note($contact));
    }

    public function test_a_business_wide_journey_keeps_writing_after_the_contact_moves(): void
    {
        $workflow = $this->journey([$this->updateFieldStep((int) $this->field->id, 'vip'), $this->endStep()], null);
        $contact = $this->contactAt($this->downtown, '12025552003');

        $enrollment = $this->drive($workflow, $contact, $this->downtown);

        $this->assertSame('vip', $this->note($contact), 'Existing Business-wide behaviour is unchanged.');
        $this->assertSame(StepRunStatus::Succeeded, $this->step($enrollment, 'update_contact_field')->status);
    }

    // =================================================================
    // Send SMS
    // =================================================================

    /** A bound journey whose node was tampered into a text (publish itself refuses one). */
    private function boundSmsJourney(): AutomationWorkflow
    {
        $workflow = $this->journey([$this->notifyStep(), $this->endStep()], $this->downtown);

        DB::table('automation_workflow_nodes')
            ->where('version_id', $workflow->published_version_id)->where('node_type', 'internal_notification')
            ->update(['node_type' => 'send_sms', 'config' => json_encode(['body' => 'Hello there'])]);

        return $workflow->fresh();
    }

    private function notifyStep(): array
    {
        return ['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'internal_notification', 'config' => ['message' => 'Heads up']];
    }

    public function test_a_bound_journey_does_not_text_a_contact_who_moved_away(): void
    {
        $workflow = $this->boundSmsJourney();
        $contact = $this->contactAt($this->downtown, '12025552011');
        $core = $this->captureSendCore(0);

        $enrollment = $this->drive($workflow, $contact, $this->downtown);

        $this->assertSame(0, $core->count(), 'Nothing is sent.');
        $step = $this->step($enrollment, 'send_sms');
        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
    }

    public function test_a_bound_journey_cannot_text_at_all_because_no_sender_can_be_proven_for_its_location(): void
    {
        $workflow = $this->boundSmsJourney();
        $contact = $this->contactAt($this->downtown, '12025552012');
        $core = $this->captureSendCore(0);

        $enrollment = $this->drive($workflow, $contact, $this->downtown, moveFirst: false);

        $this->assertSame(0, $core->count(), 'The Business-level sender is never used for a Location-bound journey.');
        $step = $this->step($enrollment, 'send_sms');
        $this->assertSame(StepRunStatus::Failed, $step->status);
        $this->assertSame('location_sender_unavailable', $step->safe_error_summary);
    }

    public function test_a_business_wide_journey_still_texts_after_the_contact_moves(): void
    {
        $workflow = $this->journey([$this->smsStep('Hello there'), $this->endStep()], null);
        $contact = $this->contactAt($this->downtown, '12025552013');
        $core = $this->captureSendCore(1);

        $enrollment = $this->drive($workflow, $contact, $this->downtown);

        $this->assertSame(1, $core->count(), 'Existing Business-wide behaviour is unchanged.');
        $this->assertSame(StepRunStatus::Succeeded, $this->step($enrollment, 'send_sms')->status);
    }

    public function test_publish_refuses_a_text_in_a_location_bound_workflow_and_names_why(): void
    {
        try {
            $this->journey([$this->smsStep('Hello there'), $this->endStep()], $this->downtown);
            $this->fail('A Location-bound workflow with a text step must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cannot send text messages yet', json_encode($exception->errors()));
        }

        // The same text is fine in a Business-wide workflow.
        $this->assertNotNull($this->journey([$this->smsStep('Hello there'), $this->endStep()], null)->published_version_id);
    }
}
