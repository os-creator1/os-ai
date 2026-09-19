<?php

namespace App\Exceptions\Calendar;

/**
 * Implementation Contract 15 §7.3 step 5 — round-robin walked its whole
 * candidate order and every member either was not eligible, not available,
 * or already busy for the interval (or the pool was empty).
 *
 * Nothing is written and THE CURSOR IS LEFT EXACTLY AS IT WAS: skipping never
 * advances it, which is what keeps the rotation non-starving (§7.3).
 */
class NoEligibleStaffAvailableException extends BookingRefusedException
{
    public static function forBookingType(int $bookingTypeId, string $startAt, string $endAt): self
    {
        return new self(
            "No eligible, available staff member for Booking Type [{$bookingTypeId}] in [{$startAt}, {$endAt})."
        );
    }
}
