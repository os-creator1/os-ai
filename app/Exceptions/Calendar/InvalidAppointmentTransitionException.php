<?php

namespace App\Exceptions\Calendar;

use App\Enums\Calendar\AppointmentStatus;

/**
 * Implementation Contract 15 §7.4 step 2 — the appointment's status, re-read
 * from persistence UNDER the tier-3 lock, is not the state this mutation
 * requires.
 *
 * This is the one exception that makes the lifecycle races deterministic: a
 * cancel that lost to a reschedule, a second cancel, and a no-show racing a
 * completion all land here, having written nothing and dispatched nothing.
 */
class InvalidAppointmentTransitionException extends BookingRefusedException
{
    public static function from(int $appointmentId, AppointmentStatus $actual, string $attempted): self
    {
        return new self(
            "Appointment [{$appointmentId}] is [{$actual->value}] and cannot be {$attempted}; only [scheduled] may transition."
        );
    }
}
