<?php

namespace App\Library\Calendar\Zoom;

use App\Models\Appointment;

/**
 * The single answer to "may a customer see a Zoom link for this appointment right now?".
 * Yes only for a Zoom appointment whose meeting is ready, whose stored link is a valid participant link, and whose Zoom time
 * equals the appointment's current time (so a link is never presented while a reschedule has not reached Zoom yet).
 */
final class ZoomLinkGate
{
    public const READY = 'ready';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const NONE = 'none';

    public static function state(Appointment $appointment): string
    {
        if ($appointment->meeting_location_type !== 'zoom') {
            return self::NONE;
        }

        if ($appointment->zoom_status === 'failed') {
            return self::FAILED;
        }

        if ($appointment->zoom_status === 'ready'
            && ZoomJoinUrl::valid($appointment->zoom_join_url)
            && $appointment->zoom_synced_start_at !== null
            && $appointment->zoom_synced_start_at->copy()->utc()->equalTo($appointment->start_at->copy()->utc())) {
            return self::READY;
        }

        return self::PENDING;
    }

    public static function joinUrl(Appointment $appointment): ?string
    {
        return self::state($appointment) === self::READY ? (string) $appointment->zoom_join_url : null;
    }
}
