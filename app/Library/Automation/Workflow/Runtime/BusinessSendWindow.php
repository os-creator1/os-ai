<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Models\Business;
use Illuminate\Support\Carbon;

/**
 * Answers "may the engine message this person right now?" in the Business's own
 * timezone, and if not, "when is the next moment it may?".
 *
 * Pure computation over (timezone, quiet hours, optional recorded opening hours):
 * no I/O beyond reading the Business's primary Location once. Works in local
 * wall-clock minutes per calendar day, so a daylight-saving change never shifts a
 * window by an hour.
 */
final class BusinessSendWindow
{
    private const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * @param  array<string, mixed>|null  $hours  Location hours: day => list of {open, close} "HH:MM", or null when never recorded
     */
    public function __construct(
        private readonly string $timezone,
        private readonly ?array $hours,
        private readonly int $quietStart = 21 * 60,
        private readonly int $quietEnd = 8 * 60,
        private readonly bool $requireBusinessHours = false,
        private readonly int $searchDays = 9,
    ) {
    }

    public static function forBusiness(Business $business, bool $requireBusinessHours = false): self
    {
        $timezone = trim((string) $business->timezone);
        $timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (string) config('app.timezone', 'UTC');
        $hours = $business->primaryLocation()->first()?->hours;

        return new self(
            $timezone,
            is_array($hours) ? $hours : null,
            self::minutes((string) config('send_window.quiet_start', '21:00')),
            self::minutes((string) config('send_window.quiet_end', '08:00')),
            $requireBusinessHours,
            (int) config('send_window.search_days', 9),
        );
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function isQuiet(Carbon $at): bool
    {
        $m = $this->localMinutes($at);

        return $this->quietStart > $this->quietEnd
            ? ($m >= $this->quietStart || $m < $this->quietEnd)   // overnight window, e.g. 21:00-08:00
            : ($m >= $this->quietStart && $m < $this->quietEnd);
    }

    /** True when the Location has recorded hours and $at is inside one of the day's periods. Null hours => true (unknown is not closed). */
    public function isOpen(Carbon $at): bool
    {
        if ($this->hours === null) {
            return true;
        }

        $local = $at->clone()->setTimezone($this->timezone);
        $m = $local->hour * 60 + $local->minute;

        foreach ($this->periodsFor($local) as [$open, $close]) {
            if ($m >= $open && $m < $close) {
                return true;
            }
        }

        return false;
    }

    public function isAllowed(Carbon $at): bool
    {
        return ! $this->isQuiet($at) && (! $this->requireBusinessHours || $this->isOpen($at));
    }

    /** The earliest moment at or after $at when sending is allowed (UTC). Returns $at itself if already allowed. */
    public function nextAllowed(Carbon $at): Carbon
    {
        if ($this->isAllowed($at)) {
            return $at->clone()->utc();
        }

        $local = $at->clone()->setTimezone($this->timezone);

        for ($d = 0; $d <= $this->searchDays; $d++) {
            $day = $local->clone()->startOfDay()->addDays($d);
            $startM = $d === 0 ? $local->hour * 60 + $local->minute : 0;

            foreach ($this->allowedIntervals($day) as [$from, $to]) {
                $candidate = max($from, $startM);
                if ($candidate < $to) {
                    return $day->clone()->setTime(intdiv($candidate, 60), $candidate % 60)->utc();
                }
            }
        }

        // Hours recorded but never open inside the search horizon: fall back to the next quiet-hours end so
        // the journey is never parked forever.
        $fallback = $local->clone()->startOfDay()->addDays($this->quietEnd <= $this->localMinutes($at) ? 1 : 0)->setTime(intdiv($this->quietEnd, 60), $this->quietEnd % 60);

        return $fallback->utc();
    }

    /** @return list<array{0:int,1:int}> allowed [from,to) minute intervals within one local calendar day, ascending */
    private function allowedIntervals(Carbon $day): array
    {
        $base = $this->quietStart > $this->quietEnd
            ? [[$this->quietEnd, $this->quietStart]]
            : [[0, $this->quietStart], [$this->quietEnd, 1440]];

        $open = ($this->requireBusinessHours && $this->hours !== null) ? $this->periodsFor($day) : [[0, 1440]];

        $out = [];
        foreach ($base as [$a, $b]) {
            foreach ($open as [$c, $e]) {
                $from = max($a, $c);
                $to = min($b, $e);
                if ($from < $to) {
                    $out[] = [$from, $to];
                }
            }
        }
        usort($out, fn ($x, $y) => $x[0] <=> $y[0]);

        return $out;
    }

    /** @return list<array{0:int,1:int}> */
    private function periodsFor(Carbon $local): array
    {
        $periods = [];
        foreach ((array) ($this->hours[self::DAYS[$local->dayOfWeek]] ?? []) as $p) {
            if (is_array($p) && isset($p['open'], $p['close'])) {
                $periods[] = [self::minutes((string) $p['open']), self::minutes((string) $p['close'])];
            }
        }

        return $periods;
    }

    private function localMinutes(Carbon $at): int
    {
        $local = $at->clone()->setTimezone($this->timezone);

        return $local->hour * 60 + $local->minute;
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }
}
