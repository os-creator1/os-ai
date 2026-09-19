<?php

namespace App\Exceptions\Calendar;

/**
 * Implementation Contract 15 §7.6 step 3 — the requested interval overlaps
 * something this staff member is already busy with, ACROSS EVERY LOCATION.
 * That cross-Location reach is the point: tier 2 serializes one staff
 * member's whole timeline, so conflict prevention is structural rather than
 * a per-Location check (§7.1, Blueprint §12).
 */
class AppointmentSlotUnavailableException extends BookingRefusedException
{
    public static function forStaff(int $staffUserId, string $startAt, string $endAt): self
    {
        return new self(
            "Staff member [{$staffUserId}] already has a scheduled appointment overlapping [{$startAt}, {$endAt})."
        );
    }
}
