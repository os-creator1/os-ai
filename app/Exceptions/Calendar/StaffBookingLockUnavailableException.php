<?php

namespace App\Exceptions\Calendar;

/**
 * Implementation Contract 15 §7.2 step 3 — the serialization row for a staff
 * member could not be obtained even after one re-ensure.
 *
 * Deliberately fails the whole operation rather than proceeding: a booking
 * that cannot take tier 2 is a booking that is not serialized, and an
 * unserialized booking is exactly the double-booking this contract exists to
 * prevent. Never retried unboundedly.
 */
class StaffBookingLockUnavailableException extends BookingRefusedException
{
    public static function forStaff(int $staffUserId): self
    {
        return new self(
            "Could not acquire the booking lock row for staff member [{$staffUserId}]; refusing to proceed unserialized."
        );
    }
}
