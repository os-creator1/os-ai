<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Jobs\Calendar\SyncZoomMeeting;
use App\Models\Appointment;

/**
 * Zoom follows the appointment lifecycle. The events are dispatched AFTER the booking transaction commits, so this only ever
 * queues work; the Zoom API is called by SyncZoomMeeting, outside every lock. One job reconciles to the appointment's current
 * state, so scheduled / rescheduled / cancelled all dispatch the same thing and a stale job cannot restore an old time.
 */
class SyncZoomMeetingOnAppointmentEvents
{
    public function handleScheduled(AppointmentScheduled $event): void
    {
        $this->dispatch($event->appointmentId);
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $this->dispatch($event->appointmentId);
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        $this->dispatch($event->appointmentId);
    }

    private function dispatch(int $appointmentId): void
    {
        // Only appointments that chose Zoom are touched: ordinary appointments never queue anything.
        if (Appointment::query()->whereKey($appointmentId)->where('meeting_location_type', 'zoom')->exists()) {
            SyncZoomMeeting::dispatch($appointmentId);
        }
    }
}
