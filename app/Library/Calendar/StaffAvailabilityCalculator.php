<?php

namespace App\Library\Calendar;

use App\Models\BusinessLocation;
use App\Models\StaffAvailabilityRule;
use App\Models\StaffTimeOff;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 15 §5.2/§5.3 — "is this staff member AVAILABLE for
 * this interval", re-derived from canonical Calendar B data every time.
 *
 * Availability is deliberately separate from conflict detection (§7.6): this
 * answers "are they supposed to be working then", while BookingConflictDetector
 * answers "is that time already taken". A booking needs both.
 *
 * TWO SOURCES, and they have different scopes on purpose:
 *
 *   staff_availability_rules — Location-bound recurring windows, stored as
 *   LOCAL time-of-day in the Location's Business's timezone (§5.2). They are
 *   deliberately never converted to a fixed UTC offset at write time, so this
 *   reader must do the conversion per calendar date — which is what keeps a
 *   recurring 09:00–17:00 rule correct across a DST boundary.
 *
 *   staff_time_off — USER-GLOBAL UTC intervals (§5.3). Time off removes a
 *   staff member from every Location at once, so it is queried by User alone
 *   and never filtered by Location. That asymmetry is the contract's, not an
 *   oversight.
 */
class StaffAvailabilityCalculator
{
    /**
     * A booking must fit ENTIRELY inside one recurring window on one local
     * day, and must not touch any time off.
     *
     * Requiring a single window is a deliberate V1 rule with two consequences
     * worth naming: an interval that spans local midnight can never be
     * available (no single day's window can contain it), and two adjacent
     * windows do not merge into one bookable span even when they touch. Both
     * follow from §5.2's own framing of a rule as one continuous window, and
     * neither silently lets a booking land outside somebody's stated hours.
     */
    public function isAvailable(
        int $staffUserId,
        BusinessLocation $location,
        CarbonInterface $startAt,
        CarbonInterface $endAt
    ): bool {
        if (! $startAt->lessThan($endAt)) {
            return false;
        }

        if ($this->hasTimeOffOverlapping($staffUserId, $startAt, $endAt)) {
            return false;
        }

        return $this->fitsARecurringWindow($staffUserId, $location, $startAt, $endAt);
    }

    /**
     * §5.3 — User-global, so no Location filter. A half-open overlap test:
     * time off ending exactly when the appointment starts does not block it.
     */
    private function hasTimeOffOverlapping(int $staffUserId, CarbonInterface $startAt, CarbonInterface $endAt): bool
    {
        return StaffTimeOff::query()
            ->where('staff_user_id', $staffUserId)
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
    }

    private function fitsARecurringWindow(
        int $staffUserId,
        BusinessLocation $location,
        CarbonInterface $startAt,
        CarbonInterface $endAt
    ): bool {
        $timezone = $this->timezoneFor($location);

        $localStart = Carbon::instance($startAt->toDateTime())->setTimezone($timezone);
        $localEnd = Carbon::instance($endAt->toDateTime())->setTimezone($timezone);

        // A window lives on one local day. An interval that crosses local
        // midnight therefore cannot fit any single window, and saying so here
        // is clearer than letting the comparison below fail obscurely.
        if (! $localStart->isSameDay($localEnd) && ! $this->endsExactlyAtMidnight($localEnd)) {
            return false;
        }

        // Carbon's dayOfWeek is already 0=Sunday..6=Saturday, matching
        // staff_availability_rules.day_of_week (§5.2).
        $rules = StaffAvailabilityRule::query()
            ->where('business_location_id', $location->id)
            ->where('staff_user_id', $staffUserId)
            ->where('day_of_week', $localStart->dayOfWeek)
            ->get();

        foreach ($rules as $rule) {
            $windowStart = $this->localTimeOn($localStart, (string) $rule->start_time, $timezone);
            $windowEnd = $this->localTimeOn($localStart, (string) $rule->end_time, $timezone);

            // '24:00' is a legitimate close time in this codebase's own hours
            // vocabulary; Carbon cannot hold it as a time-of-day, so it is
            // expressed as midnight on the following day.
            if ($windowEnd->lessThanOrEqualTo($windowStart)) {
                $windowEnd = $windowEnd->copy()->addDay();
            }

            if ($localStart->greaterThanOrEqualTo($windowStart) && $localEnd->lessThanOrEqualTo($windowEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An interval finishing exactly at local midnight still belongs to the
     * previous day's window, so it is not treated as spanning two days.
     */
    private function endsExactlyAtMidnight(Carbon $localEnd): bool
    {
        return $localEnd->format('H:i:s') === '00:00:00';
    }

    private function localTimeOn(Carbon $localDay, string $timeOfDay, string $timezone): Carbon
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $timeOfDay)), 3, 0);

        return Carbon::create(
            $localDay->year,
            $localDay->month,
            $localDay->day,
            $hour,
            $minute,
            $second,
            $timezone
        );
    }

    /**
     * §3.3/§5.2 — the Business is the single source of timezone truth; there
     * is no per-Location override and business_locations carries no timezone
     * column. Read defensively, exactly as the rest of the app does.
     */
    private function timezoneFor(BusinessLocation $location): string
    {
        $business = $location->business;

        return (string) ($business?->timezone ?: config('app.timezone', 'UTC'));
    }
}
