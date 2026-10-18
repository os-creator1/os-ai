<?php

namespace App\Library\Calendar\DemoBooking;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The guest's cancel / reschedule link for a /book-demo appointment.
 *
 * It is a signed URL (Laravel's HMAC over the full URL, keyed by APP_KEY) that
 * expires, and it carries the appointment's reschedule count. The count is how
 * a link is retired: the moment the appointment moves, every link issued for
 * the earlier time stops matching, and a cancelled or past appointment matches
 * nothing. Links are minted per message at SEND time, so a reminder after a
 * reschedule always carries a link for the current time.
 *
 * Only appointments of the designated demo Booking Type ever get one.
 */
class DemoBookingManageLinks
{
    public static function urlFor(Appointment $appointment): ?string
    {
        $designated = strtolower((string) config('demo_booking.booking_type_uuid'));

        if ($designated === '' || $appointment->start_at === null) {
            return null;
        }

        $type = $appointment->bookingType;

        if ($type === null || strtolower((string) $type->public_booking_uuid) !== $designated) {
            return null;
        }

        $start = $appointment->start_at->copy();
        $expires = Carbon::now()->addDays((int) config('demo_booking.manage_link_days', 30));
        $expires = $expires->greaterThan($start) ? $start : $expires;

        if ($expires->lessThanOrEqualTo(Carbon::now())) {
            return null;
        }

        return URL::temporarySignedRoute('demo.booking.manage', $expires, [
            'appointmentUid' => $appointment->uid,
            'rc' => (int) $appointment->reschedule_count,
        ]);
    }
}
