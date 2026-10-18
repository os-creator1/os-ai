<?php

namespace Tests\Feature\CustomFields;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Runtime\AutomationMergeContextFactory;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Models\Appointment;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Calendar\Concerns\CreatesBookingEngineFixtures;
use Tests\TestCase;

/**
 * Appointment merge fields come from the enrollment's OWN Appointment trigger
 * fact, never from a guessed "latest" appointment.
 */
class MergeFieldAppointmentContextTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBookingEngineFixtures;
    use BuildsFoundationWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);
        $this->bootCalendarAuthorityFixtures();
    }

    private function book(): Appointment
    {
        $staff = $this->bookableStaff();

        return $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart(), (int) $this->owner->user_id);
    }

    public function test_an_appointment_triggered_run_resolves_that_appointments_fields(): void
    {
        $this->business->forceFill(['timezone' => 'UTC'])->save();
        $this->triggerWorkflow($this->business->fresh(), WorkflowTriggerType::AppointmentScheduled);
        $appointment = $this->book();
        $enrollment = AutomationEnrollment::query()->sole();
        $contact = Contacts::query()->findOrFail($appointment->contact_id);
        $expected = \Carbon\Carbon::parse($appointment->start_at)->utc();

        $text = '{{appointment.start_date}} at {{appointment.start_time}} ({{appointment.timezone}}) | {{location.name}}';
        $rendered = ContactMergeFields::renderForEnrollment($text, $contact, $enrollment, $this->business->fresh());

        $this->assertStringContainsString($expected->format('j M Y') . ' at ' . $expected->format('g:i A') . ' (UTC)', $rendered);
        $this->assertStringContainsString($this->locationA->name, $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    public function test_a_run_with_a_different_trigger_gets_no_appointment_even_when_one_exists(): void
    {
        $this->triggerWorkflow($this->business, WorkflowTriggerType::AppointmentScheduled);
        $appointment = $this->book();
        $contact = Contacts::query()->findOrFail($appointment->contact_id);

        $manual = new AutomationEnrollment([
            'business_id' => $this->business->id, 'contact_id' => $contact->id,
            'trigger_type' => WorkflowTriggerType::ManualEnrollment, 'trigger_occurrence_key' => 'manual-1',
        ]);

        $context = app(AutomationMergeContextFactory::class)->for($manual, $this->business->fresh(), $contact);

        $this->assertNull($context->appointment, 'No trigger fact, no appointment — it never looks one up.');
        $this->assertSame('[]', ContactMergeFields::renderForEnrollment('[{{appointment.start_date}}]', $contact, $manual, $this->business->fresh()));
    }

    public function test_an_appointment_key_naming_another_contacts_appointment_is_ignored(): void
    {
        $this->triggerWorkflow($this->business, WorkflowTriggerType::AppointmentScheduled);
        $appointment = $this->book();
        $stranger = Contacts::query()->findOrFail($this->contactId());

        $forged = new AutomationEnrollment([
            'business_id' => $this->business->id, 'contact_id' => $stranger->id,
            'trigger_type' => WorkflowTriggerType::AppointmentScheduled, 'trigger_occurrence_key' => 'appointment_scheduled:' . $appointment->id,
        ]);

        $this->assertSame('[]', ContactMergeFields::renderForEnrollment('[{{appointment.start_date}}]', $stranger, $forged, $this->business->fresh()));
    }
}
