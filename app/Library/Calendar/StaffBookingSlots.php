<?php

namespace App\Library\Calendar;

use App\Models\BookingType;
use App\Models\BusinessLocation;
use Illuminate\Support\Carbon;

/**
 * What the staff "Book appointment" screen may OFFER for one Booking Type.
 *
 * A thin asker over PublicSlotFinder, not a second availability engine: every
 * offered start is decided by the same canonical pair the public scheduler uses
 * (StaffAvailabilityCalculator + BookingConflictDetector) and is re-checked by
 * AppointmentBookingService under lock when the form is submitted. Nothing is
 * computed in the browser and nothing is invented here — a day or time that the
 * canonical pair does not offer is simply not shown.
 *
 * The Business timezone is the only clock: the staff member sees, and the form
 * posts, Business-local dates and times (the same values store() expects).
 */
class StaffBookingSlots
{
    public function __construct(
        private readonly PublicSlotFinder $slots,
        private readonly CalendarLocationResolver $locations,
    ) {
    }

    /**
     * The staff to search: `auto` is the Booking Type's own eligible pool (what
     * round-robin draws from); a user id is that one member, only while they are
     * still eligible at this Location. Anything else offers nothing.
     *
     * @return list<int>
     */
    public function staffIds(BookingType $type, BusinessLocation $location, string $staff): array
    {
        if ($staff === 'auto') {
            return $type->staff()->orderBy('users.id')->pluck('users.id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $this->locations->isEligible($id, $location))
                ->values()->all();
        }

        if (! ctype_digit($staff) || ! $this->locations->isEligible((int) $staff, $location)) {
            return [];
        }

        return [(int) $staff];
    }

    /**
     * Offered start times on one Business-local day, soonest first.
     *
     * @param  list<int>  $staffIds
     * @return list<array{time: string, label: string, end_label: string}>
     */
    public function day(BusinessLocation $location, BookingType $type, array $staffIds, string $date, string $timezone): array
    {
        if ($staffIds === []) {
            return [];
        }

        return array_map(
            static fn (array $slot): array => ['time' => $slot['time'], 'label' => $slot['label'], 'end_label' => $slot['end_label']],
            $this->slots->slotsForDay($location, $type, $staffIds, $date, $timezone, $timezone),
        );
    }

    /**
     * The seven Monday-first days containing $anchor, each flagged with whether
     * it has at least one offered start.
     *
     * @param  list<int>  $staffIds
     * @return list<array{date: string, weekday: string, day: string, available: bool}>
     */
    public function week(BusinessLocation $location, BookingType $type, array $staffIds, string $anchor, string $timezone): array
    {
        $monday = Carbon::createFromFormat('!Y-m-d', $anchor, $timezone)->startOfWeek(Carbon::MONDAY);
        $open = [];

        if ($staffIds !== []) {
            $months = [$monday->format('Y-m'), $monday->copy()->addDays(6)->format('Y-m')];

            foreach (array_unique($months) as $month) {
                $open = array_merge($open, $this->slots->availableDates($location, $type, $staffIds, $month, $timezone, $timezone));
            }
        }

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $monday->copy()->addDays($i);
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->format('D'),
                'day' => $day->format('j'),
                'available' => in_array($day->toDateString(), $open, true),
            ];
        }

        return $days;
    }

    /** The earliest Business-local date, from $from on, that has an offered start (within the type's window), or null. */
    public function firstAvailableDate(BusinessLocation $location, BookingType $type, array $staffIds, string $from, string $timezone): ?string
    {
        if ($staffIds === []) {
            return null;
        }

        $start = Carbon::createFromFormat('!Y-m-d', $from, $timezone);
        $months = array_unique([$start->format('Y-m'), $start->copy()->addMonth()->format('Y-m')]);
        $dates = [];

        foreach ($months as $month) {
            $dates = array_merge($dates, $this->slots->availableDates($location, $type, $staffIds, $month, $timezone, $timezone));
        }

        sort($dates);

        foreach ($dates as $date) {
            if ($date >= $from) {
                return $date;
            }
        }

        return null;
    }
}
