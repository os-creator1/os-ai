<?php

namespace App\Library\Demo\BrightFrame;

use App\Enums\Calendar\AppointmentStatus;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\StaffAvailabilityService;
use App\Models\Appointment;
use App\Models\BookingType;

/**
 * Calendar: three booking types (customer notifications OFF so nothing is
 * ever emailed or texted), weekly hours, and appointments in the past and the
 * coming three weeks. Appointments are written as plain rows rather than
 * through the booking service, so no AppointmentScheduled event, reminder or
 * notification is ever raised for these made-up people.
 */
final class CalendarStage implements DemoStage
{
    private const TYPES = [
        ['Photo Booth Consultation', 30, '#7c3aed', 'A quick call or visit to match the right booth and package to your event.'],
        ['Venue Walkthrough', 60, '#0ea5e9', 'We visit your venue to plan booth placement, power and guest flow.'],
        ['Final Details Check-in', 30, '#10b981', 'Confirm timeline, photo template and day-of logistics.'],
    ];

    /**
     * [contact index, type index, day offset, hour, minute, status, opportunity title|null]
     */
    private const APPOINTMENTS = [
        [12, 2, -9, 10, 0, 'completed', 'Rahman Wedding: Signature Package'],
        [15, 1, -8, 14, 0, 'completed', 'Matthews Wedding: Essential Package'],
        [16, 0, -7, 11, 0, 'cancelled', 'Moretti Wedding: Luxe Package'],
        [13, 0, -6, 15, 30, 'completed', 'Southline Logistics Holiday Party: Glam Mirror Booth'],
        [17, 0, -4, 9, 30, 'no_show', 'Sutton Graduation Open House: Classic Booth'],
        [14, 2, -3, 16, 0, 'completed', 'Liu Sweet Sixteen: Classic Booth'],
        [0, 0, 1, 10, 0, 'scheduled', 'Thompson Wedding: Signature Package'],
        [5, 1, 1, 14, 0, 'scheduled', 'Piedmont Health Gala: Branded Activation'],
        [2, 0, 2, 11, 30, 'scheduled', 'Nair-Shah Wedding: Luxe Package'],
        [8, 1, 4, 10, 0, 'scheduled', 'Whitfield Wedding: Signature Package'],
        [9, 0, 6, 15, 0, 'scheduled', 'Carolina Craft Distillers Launch: 360 Video Booth'],
        [1, 0, 7, 9, 30, 'scheduled', 'Queen City Software Summer Mixer: 360 Video Booth'],
        [6, 0, 8, 13, 0, 'scheduled', 'Lindqvist Prom Night: Classic Booth'],
        [3, 0, 10, 16, 0, 'scheduled', 'Bennett 40th Birthday: Classic Booth'],
        [4, 0, 13, 11, 0, 'scheduled', null],
        [10, 1, 15, 10, 30, 'scheduled', null],
    ];

    public function __construct(
        private readonly BookingTypeManager $types,
        private readonly StaffAvailabilityService $availability,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $location = $context->location;
        $types = [];

        foreach (self::TYPES as [$name, $minutes, $color, $description]) {
            $type = BookingType::query()->where('business_location_id', $location->id)->where('name', $name)->first()
                ?? $this->types->create($location, [
                    'name' => $name,
                    'description' => $description,
                    'duration_minutes' => $minutes,
                    'color' => $color,
                    'booking_window_days' => 60,
                    'minimum_notice_minutes' => 240,
                    'slot_interval_minutes' => 30,
                    'notify_email' => false,
                    'notify_sms' => false,
                    'reminder_offsets' => [],
                ], $context->user->id);

            // Re-assert on every run: a demo booking type must never notify anyone.
            $type->forceFill(['notify_email' => false, 'notify_sms' => false, 'reminder_offsets' => []])->save();
            $this->types->syncStaff($type, [$context->user->id]);
            $types[] = $type->fresh();
        }

        $week = [];
        foreach ([1, 2, 3, 4, 5] as $day) {
            $week[$day] = [['start' => '09:00', 'end' => '17:00']];
        }
        $week[6] = [['start' => '10:00', 'end' => '14:00']];

        $this->availability->replaceWeek($context->workspace, $context->business, $location, $context->user->id, $week, $context->user->id);

        $contacts = array_values($context->refs['contacts']);
        $created = 0;

        foreach (self::APPOINTMENTS as [$contactIndex, $typeIndex, $dayOffset, $hour, $minute, $status, $dealTitle]) {
            $type = $types[$typeIndex];
            // Keep every booking inside the weekly hours set above: no Sundays, Saturdays only 10:00-14:00.
            $day = $context->now->setTimezone($context->business->timezone)->addDays($dayOffset);
            if ($day->dayOfWeekIso === 7) {
                $dayOffset++;
            } elseif ($day->dayOfWeekIso === 6) {
                [$hour, $minute] = [11, 0];
            }
            $start = $context->localTime($dayOffset, $hour, $minute);

            $exists = Appointment::query()
                ->where('business_location_id', $location->id)
                ->where('contact_id', $contacts[$contactIndex]->id)
                ->where('booking_type_id', $type->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $appointment = Appointment::create([
                'business_location_id' => $location->id,
                'booking_type_id' => $type->id,
                'staff_user_id' => $context->user->id,
                'contact_id' => $contacts[$contactIndex]->id,
                'crm_opportunity_id' => $dealTitle === null ? null : ($context->refs['opportunities'][$dealTitle]->id ?? null),
                'created_by_user_id' => $context->user->id,
                'status' => AppointmentStatus::Scheduled,
                'start_at' => $start,
                'end_at' => $start->addMinutes((int) $type->duration_minutes),
                'reschedule_count' => 0,
            ]);

            if ($status !== 'scheduled') {
                $appointment->forceFill([
                    'status' => AppointmentStatus::from($status),
                    'resolved_at' => $start->addMinutes((int) $type->duration_minutes),
                    'resolved_by_user_id' => $context->user->id,
                    'cancellation_reason' => $status === 'cancelled' ? 'Client chose a venue with an in-house booth' : null,
                ])->save();
            }
            $created++;
        }

        $context->refs['booking_types'] = $types;
        $context->note('booking_types', count($types));
        $context->note('appointments', Appointment::query()->where('business_location_id', $location->id)->count());
        $context->note('appointments_created_now', $created);
    }
}
