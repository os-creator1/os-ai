<?php

namespace App\Exceptions\Calendar;

/**
 * Implementation Contract 15 §5.2/§5.3, §7.3 — the staff member is not
 * *available* for the interval: either no recurring availability window at
 * this Location covers it, or User-global time off overlaps it.
 *
 * Distinct from AppointmentSlotUnavailableException, which means the window
 * exists but is already taken.
 */
class StaffNotAvailableException extends BookingRefusedException
{
    public static function forStaff(int $staffUserId, string $startAt, string $endAt): self
    {
        return new self(
            "Staff member [{$staffUserId}] is not available for [{$startAt}, {$endAt})."
        );
    }
}
