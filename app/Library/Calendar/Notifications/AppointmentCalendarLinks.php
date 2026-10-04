<?php

namespace App\Library\Calendar\Notifications;

/**
 * The calendar actions a booking email offers: the same Google Calendar template
 * link and .ics the public booking page builds in the browser, produced on the
 * server so an email can carry them. Unlike the browser's .ics, the UID is STABLE
 * (the appointment's own uid) and SEQUENCE follows the reschedule count, so
 * opening a later email updates the calendar entry instead of duplicating it.
 */
final class AppointmentCalendarLinks
{
    public static function googleUrl(AppointmentNotificationDetails $details): string
    {
        $url = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . rawurlencode($details->title())
            . '&dates=' . self::compact($details->startUtc) . '/' . self::compact($details->endUtc);

        if ($details->where !== null) {
            $url .= '&location=' . rawurlencode($details->where);
        }

        if ($details->instructions !== null) {
            $url .= '&details=' . rawurlencode($details->instructions);
        }

        return $url;
    }

    public static function ics(AppointmentNotificationDetails $details): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'booking.local';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Business OS//Booking//EN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $details->appointmentUid . '@' . $host,
            'SEQUENCE:' . $details->sequence,
            'DTSTAMP:' . self::compact(now('UTC')),
            'DTSTART:' . self::compact($details->startUtc),
            'DTEND:' . self::compact($details->endUtc),
            'SUMMARY:' . self::escape($details->title()),
        ];

        if ($details->where !== null) {
            $lines[] = 'LOCATION:' . self::escape($details->where);
        }

        if ($details->instructions !== null) {
            $lines[] = 'DESCRIPTION:' . self::escape($details->instructions);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function compact(\DateTimeInterface $moment): string
    {
        return \Illuminate\Support\Carbon::instance($moment)->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
    }
}
