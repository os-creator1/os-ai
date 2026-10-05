<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Library\Calendar\Notifications\AppointmentNotificationScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Booking Notifications V1 — keeps the notification ledger in step with the
 * appointment: a cancel withdraws everything still pending, a reschedule retires
 * the reminders bound to the old start and schedules new ones.
 *
 * Both handlers are idempotent and read the appointment's CURRENT state, so a
 * redelivered or late event is harmless. This is a convenience, not the safety:
 * the sender independently refuses a cancelled appointment and a reminder bound to
 * a start the appointment no longer has.
 *
 * It sits NEXT TO the Automations listener (EnrollFromAppointmentEvent), never
 * instead of it: optional Business Automations keep reacting to the same events.
 */
class KeepAppointmentNotificationsInStep implements ShouldQueue
{
    public function __construct(private readonly AppointmentNotificationScheduler $scheduler)
    {
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $this->scheduler->onRescheduled($event->appointmentId);
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        $this->scheduler->onCancelled($event->appointmentId);
    }
}
