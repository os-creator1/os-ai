<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;

/**
 * The facts an appointment trigger hands a workflow run — identifiers only, all
 * belonging to one Business and one of its Locations.
 *
 * Built from the persisted `appointments` row joined to its Location. An
 * appointment's Business, Location and Contact are fixed for its life (the
 * Calendar refuses to change a Location, and a reschedule moves time and staff
 * only), so the facts read the same when a queued job runs late, when the event
 * is replayed, and when a journey asks again from its enrollment's occurrence
 * key (`appointment_scheduled:{id}`, `appointment_cancelled:{id}`,
 * `appointment_rescheduled:{id}:{n}`).
 *
 * No time, staff name or contact detail is carried: times are mutable, and the
 * Contact is identified by id alone.
 */
final readonly class AppointmentTriggerContext
{
    public function __construct(
        public WorkflowTriggerType $triggerType,
        public int $businessId,
        public int $locationId,
        public int $appointmentId,
        public int $contactId,
        public int $bookingTypeId,
        public string $occurrenceKey,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'trigger_type' => $this->triggerType->value,
            'business_id' => $this->businessId,
            'location_id' => $this->locationId,
            'appointment_id' => $this->appointmentId,
            'contact_id' => $this->contactId,
            'booking_type_id' => $this->bookingTypeId,
            'occurrence_key' => $this->occurrenceKey,
        ];
    }
}
