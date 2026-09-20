<?php

namespace App\Exceptions\Calendar;

/**
 * Implementation Contract 15 §6 — the staff member is not currently eligible
 * at the Booking Type's Location, re-derived through LocationAccessGuard at
 * this moment. A `booking_type_staff` row is configuration intent and never
 * restores eligibility on its own.
 */
class StaffNotEligibleForLocationException extends BookingRefusedException
{
    public static function forStaff(int $staffUserId, int $businessLocationId): self
    {
        return new self(
            "Staff member [{$staffUserId}] is not currently eligible at Location [{$businessLocationId}]."
        );
    }
}
