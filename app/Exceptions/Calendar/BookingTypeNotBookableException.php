<?php

namespace App\Exceptions\Calendar;

/**
 * V1 completion — a NEW appointment was requested against a Booking Type that
 * is no longer active. Raised inside the booking transaction, under the
 * staff member's tier-2 lock, so a deactivation that committed before the
 * lock was granted can never be outrun by a request that resolved the type
 * earlier (a stale route-bound model, a public page rendered before the
 * owner switched the type off).
 *
 * Creation only: existing appointments of a deactivated type stay
 * reschedulable and resolvable.
 */
class BookingTypeNotBookableException extends BookingRefusedException
{
    public static function forBookingType(int $bookingTypeId): self
    {
        return new self("Booking Type [{$bookingTypeId}] is not accepting bookings.");
    }
}
