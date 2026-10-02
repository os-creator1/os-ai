<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Listeners\Automation\Workflow\EnrollFromAppointmentEvent;
use App\Models\Appointment;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Calendar\Concerns\CreatesBookingEngineFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — appointment facts.
 *
 * The Location is the appointment's own, read from its row (fixed for its life);
 * the cancelled and rescheduled events carry no Contact, and the Location the
 * source uses is the persisted one, not the caller's.
 */
class LocationAppointmentTriggersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBookingEngineFixtures;
    use BuildsFoundationWorkflows;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);
        $this->bootCalendarAuthorityFixtures();

        foreach ([AppointmentScheduled::class, AppointmentCancelled::class, AppointmentRescheduled::class] as $class) {
            Event::listen($class, fn ($event) => $this->events[] = $event);
        }
    }

    /** @return array<string, array<string, AutomationWorkflow>> trigger => [A, B, wide] */
    private function workflows(): array
    {
        $out = [];

        foreach ([WorkflowTriggerType::AppointmentScheduled, WorkflowTriggerType::AppointmentCancelled, WorkflowTriggerType::AppointmentRescheduled] as $type) {
            $out[$type->value] = [
                'A' => $this->triggerWorkflow($this->business, $type, ['business_location_id' => $this->locationA->id]),
                'B' => $this->triggerWorkflow($this->business, $type, ['business_location_id' => $this->locationB->id]),
                'wide' => $this->triggerWorkflow($this->business, $type),
            ];
        }

        return $out;
    }

    private function bookAt(BusinessLocation $location, string $time = '10:00:00'): Appointment
    {
        $staff = $this->bookableStaff($location);
        $contactId = $this->contactId();
        DB::table('contacts')->where('id', $contactId)->update(['location_id' => $location->id]);

        return $this->engine()->book($this->bookingType($location), (int) $staff->id, $contactId, $this->slotStart($time), (int) $this->owner->user_id);
    }

    private function counts(array $set): array
    {
        return array_map(fn (AutomationWorkflow $w) => $this->enrollmentCount($w), $set);
    }

    public function test_each_appointment_transition_respects_the_workflow_location(): void
    {
        $w = $this->workflows();

        $appointment = $this->bookAt($this->locationA);
        $this->assertSame(['A' => 1, 'B' => 0, 'wide' => 1], $this->counts($w['appointment_scheduled']), 'Booked at A.');
        $this->assertSame((int) $this->locationA->id, (int) AutomationEnrollment::query()->where('workflow_id', $w['appointment_scheduled']['wide']->id)->value('business_location_id'));

        $this->finishJourneys();
        $this->engine()->reschedule($appointment->fresh(), $this->slotStart('13:00:00'), null, (int) $this->owner->user_id);
        $this->assertSame(['A' => 1, 'B' => 0, 'wide' => 1], $this->counts($w['appointment_rescheduled']), 'Rescheduled at A.');

        $this->finishJourneys();
        $this->engine()->cancel($appointment->fresh(), (int) $this->owner->user_id);
        $this->assertSame(['A' => 1, 'B' => 0, 'wide' => 1], $this->counts($w['appointment_cancelled']), 'Cancelled at A.');

        // The same lifecycle at Location B reaches B's workflows and not A's.
        $this->finishJourneys();
        $second = $this->bookAt($this->locationB, '15:00:00');
        $this->assertSame(['A' => 1, 'B' => 1, 'wide' => 2], $this->counts($w['appointment_scheduled']), 'Booked at B.');
        $this->assertSame((int) $this->locationB->id, (int) AutomationEnrollment::query()->where('workflow_id', $w['appointment_scheduled']['B']->id)->value('business_location_id'));

        $this->finishJourneys();
        $this->engine()->cancel($second->fresh(), (int) $this->owner->user_id);
        $this->assertSame(['A' => 1, 'B' => 1, 'wide' => 2], $this->counts($w['appointment_cancelled']));
    }

    public function test_duplicate_delivery_still_converges_under_location_scope(): void
    {
        $w = $this->workflows();
        $appointment = $this->bookAt($this->locationA);
        $this->engine()->reschedule($appointment->fresh(), $this->slotStart('13:00:00'), null, (int) $this->owner->user_id);
        $this->engine()->cancel($appointment->fresh(), (int) $this->owner->user_id);

        $before = AutomationEnrollment::query()->count();
        $this->assertSame(6, $before, 'Three transitions x (A-bound + Business-wide).');

        $this->finishJourneys();
        $listener = app(EnrollFromAppointmentEvent::class);

        foreach ($this->events as $event) {
            $listener->handle($event);
            $listener->handle($event);
        }

        $this->assertSame($before, AutomationEnrollment::query()->count());
        $this->assertSame(0, $this->enrollmentCount($w['appointment_scheduled']['B']));
    }

    public function test_the_persisted_appointment_location_beats_the_one_an_event_claims(): void
    {
        $w = $this->workflows();
        $appointment = $this->bookAt($this->locationA);
        $this->finishJourneys();
        $real = collect($this->events)->first(fn ($e) => $e instanceof AppointmentScheduled);

        // The event is rebuilt claiming Location B for an appointment that lives at A.
        app(EnrollFromAppointmentEvent::class)->handle(new AppointmentScheduled(
            (int) $appointment->id, (int) $this->business->id, (int) $this->locationB->id,
            $real->bookingTypeId, $real->staffUserId, $real->contactId, null, $real->startAt, $real->endAt, null,
        ));

        $this->assertSame(0, $this->enrollmentCount($w['appointment_scheduled']['B']), 'A forged Location never enrolls B\'s workflow.');
        $this->assertSame(1, $this->enrollmentCount($w['appointment_scheduled']['A']), 'Only the genuine booking enrolled A\'s.');
    }
}
