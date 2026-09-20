<?php

namespace App\Exceptions\Calendar;

/**
 * Implementation Contract 15 §7.4 — the appointment's staff member, re-read
 * from persistence UNDER the tier-3 lock, is not the staff member whose
 * tier-2 timeline this reschedule locked.
 *
 * A competing reschedule moved the appointment to another staff member while
 * this one waited for its locks. Proceeding would write the stale staff id
 * back over that move and place the interval on a timeline this transaction
 * does not own, so the engine refuses before any write; the caller re-reads
 * and retries against the appointment as it now is.
 */
class AppointmentStaffChangedException extends BookingRefusedException
{
    public static function forAppointment(int $appointmentId, int $lockedStaffUserId, int $actualStaffUserId): self
    {
        return new self(
            "Appointment [{$appointmentId}] moved to staff [{$actualStaffUserId}] while this change held staff [{$lockedStaffUserId}]; re-read it and retry."
        );
    }
}
