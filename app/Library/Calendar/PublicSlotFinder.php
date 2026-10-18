<?php

namespace App\Library\Calendar;

use App\Models\BookingType;
use App\Models\BusinessLocation;
use App\Models\StaffAvailabilityRule;
use Illuminate\Support\Carbon;

/**
 * The public scheduler's read side: which instants may a guest be OFFERED.
 *
 * This is a thin asker, not a second availability engine. Every offer is
 * decided by the canonical StaffAvailabilityCalculator (hours, time off, the
 * Location's Business timezone) and BookingConflictDetector (internal
 * appointments across all Locations plus external-calendar busy blocks) -
 * the very pair AppointmentBookingService re-runs under lock when the guest
 * confirms. Nothing here is recomputed in the browser.
 *
 * Two clocks, deliberately separate:
 *   - the BUSINESS timezone owns the 30-minute grid, the 30-day window and the
 *     stored appointment (the guest POSTs a business date + time);
 *   - the VISITOR timezone only decides which calendar day a slot is shown
 *     on and how it is labelled. It never moves a slot.
 *
 * The only in-memory shortcut is pruning candidates on weekdays for which no
 * eligible staff member has any availability rule, which is the same rows the
 * calculator would read; every surviving candidate is still verified by the
 * canonical pair.
 */
class PublicSlotFinder
{
    public function __construct(
        private readonly StaffAvailabilityCalculator $availability,
        private readonly BookingConflictDetector $conflicts,
    ) {
    }

    /** First and last bookable Business dates (Y-m-d): today through today + the type's booking window. */
    public function window(string $businessTimezone, BookingType $type): array
    {
        $today = Carbon::now($businessTimezone)->startOfDay();

        return [$today->toDateString(), $today->copy()->addDays($type->windowDays())->toDateString()];
    }

    /**
     * The earliest start a guest may be offered: strictly after now, and no
     * sooner than the type's minimum notice. Decided here, on the server, in UTC.
     */
    public function earliestStart(BookingType $type): Carbon
    {
        return now()->utc()->addMinutes($type->minimumNoticeMinutes());
    }

    /** Is this Business-local time a start the type's interval would offer? */
    public function onGrid(string $time, BookingType $type): bool
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return (($h * 60) + $m) % max(1, $type->slotIntervalMinutes()) === 0;
    }

    /** May a guest book exactly this instant (a Business date + resolved start)? */
    public function mayBook(BookingType $type, string $businessTimezone, string $date, Carbon $start): bool
    {
        [$first, $last] = $this->window($businessTimezone, $type);

        return $date >= $first && $date <= $last
            && $start->greaterThan(now()->utc())
            && $start->greaterThanOrEqualTo($this->earliestStart($type));
    }

    /**
     * Offered slots on one VISITOR-local day, soonest first.
     *
     * @param  list<int>  $staffIds
     * @return list<array{start: string, label: string, end_label: string, date: string, time: string}>
     *         `start` is UTC ISO-8601, `label` the visitor-local time, `date`/`time`
     *         the Business-local values the booking POST expects.
     */
    public function slotsForDay(
        BusinessLocation $location,
        BookingType $type,
        array $staffIds,
        string $visitorDate,
        string $visitorTimezone,
        string $businessTimezone,
    ): array {
        $weekdays = $this->weekdaysByStaff($location, $staffIds);
        $slots = [];
        foreach ($this->candidates($type, $visitorDate, $visitorTimezone, $businessTimezone, $weekdays) as $candidate) {
            if ($this->isOffered($location, $type, $candidate['staff'], $candidate['start'])) {
                $slots[] = [
                    'start' => $candidate['start']->toIso8601ZuluString(),
                    'label' => $candidate['start']->copy()->setTimezone($visitorTimezone)->format('g:i A'),
                    'end_label' => $candidate['start']->copy()->addMinutes((int) $type->duration_minutes)->setTimezone($visitorTimezone)->format('g:i A'),
                    'date' => $candidate['date'],
                    'time' => $candidate['time'],
                ];
            }
        }

        return $slots;
    }

    /**
     * Visitor-local dates in a month that have at least one offered slot.
     * Each day stops at its first offered slot, so a month costs a handful of
     * canonical checks per open day rather than a full slot listing.
     *
     * @param  list<int>  $staffIds
     * @return list<string>
     */
    public function availableDates(
        BusinessLocation $location,
        BookingType $type,
        array $staffIds,
        string $month,
        string $visitorTimezone,
        string $businessTimezone,
    ): array {
        $weekdays = $this->weekdaysByStaff($location, $staffIds);
        $first = Carbon::createFromFormat('!Y-m-d', $month.'-01', $visitorTimezone);
        $dates = [];
        for ($day = $first->copy(); $day->month === $first->month; $day->addDay()) {
            foreach ($this->candidates($type, $day->toDateString(), $visitorTimezone, $businessTimezone, $weekdays) as $candidate) {
                if ($this->isOffered($location, $type, $candidate['staff'], $candidate['start'])) {
                    $dates[] = $day->toDateString();
                    break;
                }
            }
        }

        return $dates;
    }

    /**
     * Grid instants (30-minute Business wall-clock labels) that fall on the
     * visitor-local day, inside the window and in the future, in order.
     * Business dates D-2..D+2 cover every possible zone pairing.
     *
     * @param  array<int, array<int, true>>  $weekdays  staff id => day_of_week set
     * @return list<array{start: Carbon, date: string, time: string, staff: list<int>}>
     */
    private function candidates(BookingType $type, string $visitorDate, string $visitorTimezone, string $businessTimezone, array $weekdays): array
    {
        [$firstDate, $lastDate] = $this->window($businessTimezone, $type);
        $now = now()->utc();
        $earliest = $this->earliestStart($type);
        $interval = max(1, $type->slotIntervalMinutes());
        $center = Carbon::createFromFormat('!Y-m-d', $visitorDate, $businessTimezone);
        $found = [];
        for ($offset = -2; $offset <= 2; $offset++) {
            $date = $center->copy()->addDays($offset)->toDateString();
            if ($date < $firstDate || $date > $lastDate) {
                continue;
            }
            $weekday = Carbon::createFromFormat('!Y-m-d', $date, $businessTimezone)->dayOfWeek;
            $staff = array_keys(array_filter($weekdays, static fn (array $days): bool => isset($days[$weekday])));
            if ($staff === []) {
                continue;
            }
            for ($minute = 0; $minute < 1440; $minute += $interval) {
                $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
                $start = $this->instantFor($date, $time, $businessTimezone);
                if ($start === null || $start->lessThanOrEqualTo($now) || $start->lessThan($earliest)
                    || $start->copy()->setTimezone($visitorTimezone)->toDateString() !== $visitorDate) {
                    continue;
                }
                $found[] = ['start' => $start, 'date' => $date, 'time' => $time, 'staff' => $staff];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $found;
    }

    /** @param list<int> $staffIds */
    private function isOffered(BusinessLocation $location, BookingType $type, array $staffIds, Carbon $start): bool
    {
        $end = $start->copy()->addMinutes((int) $type->duration_minutes);
        foreach ($staffIds as $staffId) {
            if ($this->availability->isAvailable($staffId, $location, $start, $end)
                && ! $this->conflicts->hasConflict(
                    $staffId, $start, $end, null, $type->bufferBeforeMinutes(), $type->bufferAfterMinutes()
                )) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<int, true>> */
    private function weekdaysByStaff(BusinessLocation $location, array $staffIds): array
    {
        $map = [];
        if ($staffIds === []) {
            return $map;
        }
        StaffAvailabilityRule::query()
            ->where('business_location_id', $location->id)
            ->whereIn('staff_user_id', $staffIds)
            ->get(['staff_user_id', 'day_of_week'])
            ->each(function ($rule) use (&$map): void {
                $map[(int) $rule->staff_user_id][(int) $rule->day_of_week] = true;
            });

        return $map;
    }

    /**
     * The one UTC instant a guest-visible wall-clock label names, or null when
     * it names none (the skipped hour of a spring-forward day) or two (the
     * repeated hour of a fall-back day). A label that cannot be resolved
     * exactly is never offered and never accepted, so the instant the guest
     * saw is always the instant that is booked.
     */
    public function instantFor(string $date, string $time, string $timezone): ?Carbon
    {
        $label = $date.' '.$time;
        $local = Carbon::createFromFormat('!Y-m-d H:i', $label, $timezone);
        if ($local === false || $local->format('Y-m-d H:i') !== $label) {
            return null;
        }
        $instant = $local->copy()->utc();
        foreach ([-3600, -1800, 1800, 3600] as $shift) {
            if ($instant->copy()->addSeconds($shift)->setTimezone($timezone)->format('Y-m-d H:i') === $label) {
                return null;
            }
        }

        return $instant;
    }
}
