<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Calendar\AppointmentStatus;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Models\AutomationEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 — appointment triggers: "An appointment is booked", "cancelled"
 * and "rescheduled".
 *
 * THE CALENDAR DOES NOT CALL AUTOMATIONS. AppointmentBookingService emits its own
 * after-commit lifecycle events and a queued listener hands each here. One class
 * serves the three triggers, registered once per type. There is no "confirmed"
 * trigger, because the Calendar has no confirmed state; completed and no-show
 * events exist but are not part of this vocabulary.
 *
 * WHAT IS TRUSTED. The events carry ids only (and cancelled / rescheduled carry
 * no Contact at all), so the appointment is re-read from `appointments` joined to
 * its Location, filtered on the event's appointment id, Business AND Location. A
 * row that does not agree on all three — a forged Location, another Business's
 * appointment — is no fact. The Contact is the appointment's own and must still
 * be a contact of that Business. "Cancelled" additionally requires the row to BE
 * cancelled: cancellation is terminal, so that cannot have changed since.
 *
 * THE OCCURRENCE KEY is the event's own (`appointment_scheduled:{id}`,
 * `appointment_cancelled:{id}`, `appointment_rescheduled:{id}:{reschedule count}`),
 * each a function of the appointment alone, so redelivery enrolls once while a
 * second reschedule is a genuinely new occurrence.
 */
class AppointmentTriggerSource extends FoundationTriggerSource
{
    protected function assertServes(WorkflowTriggerType $triggerType): void
    {
        if (! $triggerType->isAppointment()) {
            throw new \InvalidArgumentException('An appointment trigger source serves only appointment triggers.');
        }
    }

    /**
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    public function handle(AppointmentScheduled|AppointmentCancelled|AppointmentRescheduled $event): array
    {
        $result = $this->emptyResult();

        $expected = match (true) {
            $event instanceof AppointmentScheduled => WorkflowTriggerType::AppointmentScheduled,
            $event instanceof AppointmentCancelled => WorkflowTriggerType::AppointmentCancelled,
            default => WorkflowTriggerType::AppointmentRescheduled,
        };

        if ($expected !== $this->triggerType) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        $context = $this->contextFor(
            $this->triggerType,
            $event->businessId,
            $event->appointmentId,
            $event->occurrenceKey(),
            $event->businessLocationId,
        );

        if ($context === null) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        return $this->enrollListening($result, $context->businessId, $context->contactId, $context->occurrenceKey);
    }

    /**
     * The trigger facts a journey was started with, read back from its
     * enrollment — the same appointment row, so the same answer as when it fired.
     * Null for an enrollment another trigger started, or one whose appointment no
     * longer resolves inside its Business.
     */
    public function contextForEnrollment(AutomationEnrollment $enrollment): ?AppointmentTriggerContext
    {
        $key = (string) $enrollment->trigger_occurrence_key;

        if ($enrollment->trigger_type !== $this->triggerType
            || preg_match('/^' . preg_quote($this->triggerType->value, '/') . ':(\d+)(?::\d+)?$/', $key, $matches) !== 1) {
            return null;
        }

        return $this->contextFor($this->triggerType, (int) $enrollment->business_id, (int) $matches[1], $key, null);
    }

    private function contextFor(
        WorkflowTriggerType $type,
        int $businessId,
        int $appointmentId,
        string $occurrenceKey,
        ?int $locationId,
    ): ?AppointmentTriggerContext {
        $row = DB::table('appointments as a')
            ->join('business_locations as l', 'l.id', '=', 'a.business_location_id')
            ->where('a.id', $appointmentId)
            ->where('l.business_id', $businessId)
            ->when($locationId !== null, fn ($query) => $query->where('a.business_location_id', $locationId))
            ->first(['a.id', 'a.business_location_id', 'a.booking_type_id', 'a.contact_id', 'a.status', 'l.business_id']);

        if ($row === null) {
            return null;
        }

        if ($type === WorkflowTriggerType::AppointmentCancelled && (string) $row->status !== AppointmentStatus::Cancelled->value) {
            return null;
        }

        return new AppointmentTriggerContext(
            triggerType: $type,
            businessId: (int) $row->business_id,
            locationId: (int) $row->business_location_id,
            appointmentId: (int) $row->id,
            contactId: (int) $row->contact_id,
            bookingTypeId: (int) $row->booking_type_id,
            occurrenceKey: $occurrenceKey,
        );
    }
}
