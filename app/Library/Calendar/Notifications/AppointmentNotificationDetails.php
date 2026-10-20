<?php

namespace App\Library\Calendar\Notifications;

use App\Library\Calendar\BookingLocationLabel;
use App\Library\Mail\MailBrand;
use App\Library\Mail\MailBrandResolver;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Carbon;

/**
 * Everything a booking message may say, read from the CURRENT canonical
 * appointment, Booking Type, Location and Business — never from a snapshot of
 * the text and never invented. Built at SEND time, so a confirmation that is
 * delayed, or a reminder sent after a reschedule, always states the real time.
 *
 * Times are rendered in the BUSINESS timezone (the one the appointment was
 * booked in), with the zone named, so the date a customer reads is the date the
 * Business means regardless of where the server or the guest is.
 */
final readonly class AppointmentNotificationDetails
{
    public function __construct(
        public string $appointmentUid,
        public string $businessName,
        public string $bookingType,
        public string $date,
        public string $timeRange,
        public string $startTime,
        public string $zoneAbbr,
        public string $timezone,
        public ?string $where,
        public ?string $instructions,
        public Carbon $startUtc,
        public Carbon $endUtc,
        public int $sequence,
        public ?MailBrand $brand = null,
        /** Signed guest cancel/reschedule link; only the designated /book-demo Booking Type ever has one. */
        public ?string $manageUrl = null,
        // A valid Zoom participant link, present only when the appointment really has a ready, in-sync Zoom meeting.
        public ?string $joinUrl = null,
        // A Zoom appointment whose link could not be created yet: the email says so honestly instead of implying a link.
        public bool $zoomPending = false,
    ) {
    }

    /** @param  bool  $closed  A cancelled appointment: no Zoom link and no manage link may be offered as usable. */
    public static function for(Appointment $appointment, bool $zoomPending = false, bool $closed = false): ?self
    {
        $type = BookingType::query()->find($appointment->booking_type_id);
        $location = BusinessLocation::query()->find($appointment->business_location_id);
        $business = $location === null ? null : Business::query()->find($location->business_id);

        if ($type === null || $location === null || $business === null) {
            return null;
        }

        $zone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));

        try {
            new \DateTimeZone($zone);
        } catch (\Exception) {
            $zone = 'UTC';
        }

        $start = $appointment->start_at->copy()->utc();
        $end = $appointment->end_at->copy()->utc();
        $localStart = $start->copy()->setTimezone($zone);
        $localEnd = $end->copy()->setTimezone($zone);
        $instructions = trim((string) $type->meeting_instructions);

        return new self(
            appointmentUid: (string) $appointment->uid,
            businessName: (string) $business->name,
            bookingType: (string) $type->name,
            date: $localStart->format('l, F j, Y'),
            timeRange: $localStart->format('g:i A') . ' – ' . $localEnd->format('g:i A'),
            startTime: $localStart->format('g:i A'),
            zoneAbbr: $localStart->format('T'),
            timezone: str_replace('_', ' ', $zone) . ' (' . $localStart->format('T') . ')',
            where: self::whereLabel($appointment, $location),
            instructions: $instructions === '' ? null : $instructions,
            startUtc: $start,
            endUtc: $end,
            sequence: (int) $appointment->reschedule_count,
            brand: app(MailBrandResolver::class)->forBusiness($business),
            manageUrl: $closed ? null : \App\Library\Calendar\DemoBooking\DemoBookingManageLinks::urlFor($appointment),
            joinUrl: $closed ? null : self::joinUrlFor($appointment),
            zoomPending: ! $closed && $zoomPending && $appointment->meeting_location_type === 'zoom',
        );
    }

    /** The location line: the appointment's own meeting location when it has one, else the Location as before. */
    private static function whereLabel(Appointment $appointment, BusinessLocation $location): ?string
    {
        return match ($appointment->meeting_location_type) {
            'zoom' => 'Zoom meeting',
            'phone' => 'Phone call',
            'custom' => filled($appointment->meeting_location_custom) ? (string) $appointment->meeting_location_custom : BookingLocationLabel::for($location),
            default => BookingLocationLabel::for($location),
        };
    }

    /** Only a valid participant link for a meeting that is ready AND at the appointment's current time. */
    private static function joinUrlFor(Appointment $appointment): ?string
    {
        return \App\Library\Calendar\Zoom\ZoomLinkGate::joinUrl($appointment);
    }

    public function title(): string
    {
        return $this->bookingType . ' with ' . $this->businessName;
    }
}
