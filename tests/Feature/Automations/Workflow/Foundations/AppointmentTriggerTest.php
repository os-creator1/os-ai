<?php

namespace Tests\Feature\Automations\Workflow\Foundations;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Triggers\AppointmentTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use App\Listeners\Automation\Workflow\EnrollFromAppointmentEvent;
use App\Models\Appointment;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Calendar\Concerns\CreatesBookingEngineFixtures;
use Tests\TestCase;

/**
 * Automations x Calendar — appointment booked, cancelled and rescheduled.
 *
 * Appointments are booked, cancelled and rescheduled through the real
 * AppointmentBookingService, so each event is the one the booking engine emits
 * after its own commit. The engine's locking, round-robin and DST behaviour is
 * not touched and not re-tested here.
 */
class AppointmentTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBookingEngineFixtures;
    use BuildsFoundationWorkflows;

    /** @var list<object> every lifecycle event, in order */
    private array $events = [];

    private AutomationWorkflow $scheduled;

    private AutomationWorkflow $cancelled;

    private AutomationWorkflow $rescheduled;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);
        $this->bootCalendarAuthorityFixtures();

        foreach ([AppointmentScheduled::class, AppointmentCancelled::class, AppointmentRescheduled::class] as $class) {
            Event::listen($class, fn ($event) => $this->events[] = $event);
        }

        $this->scheduled = $this->triggerWorkflow($this->business, WorkflowTriggerType::AppointmentScheduled);
        $this->cancelled = $this->triggerWorkflow($this->business, WorkflowTriggerType::AppointmentCancelled);
        $this->rescheduled = $this->triggerWorkflow($this->business, WorkflowTriggerType::AppointmentRescheduled);
    }

    private function book(): Appointment
    {
        $staff = $this->bookableStaff();

        return $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart(), (int) $this->owner->user_id);
    }

    private function source(WorkflowTriggerType $type): AppointmentTriggerSource
    {
        return app(TriggerSourceRegistry::class)->for($type);
    }

    private function otherBusiness(): Business
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);

        return $this->createBusinessForCustomer($owner->user_id, $workspace->id);
    }

    // =================================================================
    // 17. Each canonical trigger enrolls once
    // =================================================================

    public function test_a_booked_appointment_enrolls_its_contact_once_with_its_identity(): void
    {
        $appointment = $this->book();

        $this->assertSame(1, $this->enrollmentCount($this->scheduled));
        $this->assertSame(0, $this->enrollmentCount($this->cancelled));
        $this->assertSame(0, $this->enrollmentCount($this->rescheduled));

        $enrollment = AutomationEnrollment::query()->sole();
        $this->assertSame(WorkflowTriggerType::AppointmentScheduled, $enrollment->trigger_type);
        $this->assertSame('appointment_scheduled:' . $appointment->id, $enrollment->trigger_occurrence_key);
        $this->assertSame((int) $appointment->contact_id, (int) $enrollment->contact_id);
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);

        $context = $this->source(WorkflowTriggerType::AppointmentScheduled)->contextForEnrollment($enrollment);
        $this->assertSame([
            'trigger_type' => 'appointment_scheduled',
            'business_id' => (int) $this->business->id,
            'location_id' => (int) $this->locationA->id,
            'appointment_id' => (int) $appointment->id,
            'contact_id' => (int) $appointment->contact_id,
            'booking_type_id' => (int) $appointment->booking_type_id,
            'occurrence_key' => 'appointment_scheduled:' . $appointment->id,
        ], $context->toArray());
    }

    public function test_a_cancelled_appointment_enrolls_its_contact_once(): void
    {
        $appointment = $this->book();
        $this->finishJourneys();

        $this->engine()->cancel($appointment, (int) $this->owner->user_id, 'Changed plans');

        $this->assertSame(1, $this->enrollmentCount($this->cancelled));
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $this->cancelled->id)->sole();
        $this->assertSame('appointment_cancelled:' . $appointment->id, $enrollment->trigger_occurrence_key);
        $this->assertSame((int) $appointment->contact_id, (int) $enrollment->contact_id, 'The contact is derived from the appointment: the event carries none.');
        $this->assertSame(1, $this->enrollmentCount($this->scheduled), 'Cancelling does not start the booked workflow again.');
    }

    public function test_each_reschedule_is_its_own_occurrence(): void
    {
        $appointment = $this->book();
        $this->finishJourneys();

        $this->engine()->reschedule($appointment->fresh(), $this->slotStart('12:00:00'), null, (int) $this->owner->user_id);
        $this->finishJourneys();
        $this->engine()->reschedule($appointment->fresh(), $this->slotStart('14:00:00'), null, (int) $this->owner->user_id);

        $keys = AutomationEnrollment::query()->where('workflow_id', $this->rescheduled->id)->orderBy('id')->pluck('trigger_occurrence_key')->all();

        $this->assertSame([
            'appointment_rescheduled:' . $appointment->id . ':1',
            'appointment_rescheduled:' . $appointment->id . ':2',
        ], $keys);
        $this->assertSame(1, $this->enrollmentCount($this->scheduled), 'A reschedule does not start the booked workflow again.');

        $context = $this->source(WorkflowTriggerType::AppointmentRescheduled)
            ->contextForEnrollment(AutomationEnrollment::query()->where('workflow_id', $this->rescheduled->id)->orderBy('id')->first());
        $this->assertSame((int) $appointment->id, $context->appointmentId);
        $this->assertSame((int) $this->locationA->id, $context->locationId);
    }

    public function test_the_reschedule_event_carries_a_deterministic_occurrence_without_changing_its_old_shape(): void
    {
        $appointment = $this->book();
        $this->engine()->reschedule($appointment->fresh(), $this->slotStart('12:00:00'), null, (int) $this->owner->user_id);

        $event = collect($this->events)->first(fn ($e) => $e instanceof AppointmentRescheduled);
        $this->assertSame('appointment_rescheduled:' . $appointment->id . ':1', $event->occurrenceKey());

        // An older caller that builds the event without the count still gets a stable, per-move key.
        $legacy = new AppointmentRescheduled(1, 1, 1, 2, 2, 'a', 'b', 'c', 'd', null);
        $same = new AppointmentRescheduled(1, 1, 1, 2, 2, 'a', 'b', 'c', 'd', null);
        $other = new AppointmentRescheduled(1, 1, 1, 2, 2, 'a', 'b', 'e', 'f', null);

        $this->assertSame($legacy->occurrenceKey(), $same->occurrenceKey());
        $this->assertNotSame($legacy->occurrenceKey(), $other->occurrenceKey());
    }

    // =================================================================
    // 18. Duplicate delivery converges
    // =================================================================

    public function test_duplicate_delivery_of_every_appointment_event_converges(): void
    {
        $appointment = $this->book();
        $this->engine()->reschedule($appointment->fresh(), $this->slotStart('12:00:00'), null, (int) $this->owner->user_id);
        $this->engine()->cancel($appointment->fresh(), (int) $this->owner->user_id);

        $this->assertCount(3, $this->events);
        $before = AutomationEnrollment::query()->count();
        $this->assertSame(3, $before);

        // Close the journeys so ONLY the occurrence keys can refuse a replay.
        $this->finishJourneys();

        $listener = app(EnrollFromAppointmentEvent::class);

        foreach ($this->events as $event) {
            $listener->handle($event);
            $listener->handle($event);
        }

        $this->assertSame($before, AutomationEnrollment::query()->count(), 'The same occurrence keys lose the same unique claims.');
    }

    // =================================================================
    // 19. Business and Location authority
    // =================================================================

    public function test_a_business_a_appointment_cannot_enroll_a_business_b_workflow(): void
    {
        $businessB = $this->otherBusiness();
        $workflowB = $this->triggerWorkflow($businessB, WorkflowTriggerType::AppointmentScheduled);

        $this->book();

        $this->assertSame(1, $this->enrollmentCount($this->scheduled));
        $this->assertSame(0, $this->enrollmentCount($workflowB));
    }

    public function test_forged_events_that_disagree_with_the_appointment_are_refused(): void
    {
        $appointment = $this->book();
        $this->finishJourneys();
        $businessB = $this->otherBusiness();
        $workflowB = $this->triggerWorkflow($businessB, WorkflowTriggerType::AppointmentScheduled);
        $listener = app(EnrollFromAppointmentEvent::class);
        $real = collect($this->events)->first(fn ($e) => $e instanceof AppointmentScheduled);

        $scheduled = fn (int $businessId, int $locationId, int $appointmentId) => new AppointmentScheduled(
            $appointmentId, $businessId, $locationId, $real->bookingTypeId, $real->staffUserId, $real->contactId, null, $real->startAt, $real->endAt, null,
        );

        // Location B (same Business) claimed for an appointment that lives at Location A.
        $listener->handle($scheduled((int) $this->business->id, (int) $this->locationB->id, (int) $appointment->id));
        // Business B claimed for Business A's appointment — and the reverse of that.
        $listener->handle($scheduled((int) $businessB->id, (int) $this->locationA->id, (int) $appointment->id));
        // An appointment that does not exist.
        $listener->handle($scheduled((int) $this->business->id, (int) $this->locationA->id, 999999));
        // A cancellation announced for an appointment that is still scheduled.
        $listener->handle(new AppointmentCancelled((int) $appointment->id, (int) $this->business->id, (int) $this->locationA->id, $real->staffUserId, null, null));

        $this->assertSame(1, $this->enrollmentCount($this->scheduled), 'Only the genuine booking ever enrolled.');
        $this->assertSame(0, $this->enrollmentCount($this->cancelled), 'A forged cancellation of a live appointment enrolls nobody.');
        $this->assertSame(0, $this->enrollmentCount($workflowB));
    }

    public function test_the_event_occurrence_keys_are_pure_functions_of_the_appointment(): void
    {
        $this->assertSame(
            'appointment_scheduled:7',
            (new AppointmentScheduled(7, 1, 1, 1, 1, 1, null, 'a', 'b', null))->occurrenceKey(),
        );
        $this->assertSame(
            'appointment_cancelled:7',
            (new AppointmentCancelled(7, 1, 1, 1, null, 'a reason'))->occurrenceKey(),
            'The reason text is not part of the identity.',
        );
    }
}
