<?php

namespace App\Library\Analytics;

use App\DTO\Analytics\DailySeries;
use Carbon\CarbonImmutable;

/**
 * Results — turns a B5 DailySeries into a chart-ready payload whose axis a
 * person can read: adaptive grouping, short labels, and the exact dates
 * kept for the tooltip.
 *
 * Pure presentation. It issues no query and does no timezone arithmetic:
 * every input date is already a Business-local calendar date (DailySeries
 * guarantees it), so grouping those dates can never move a message into a
 * neighbouring day. Values are summed, never re-derived, so the grouped
 * series always totals exactly what the daily series totals.
 *
 * Granularity by the number of local dates in the series:
 *
 *   up to 14   one point per day, labelled by weekday  "Mon 7"
 *   up to 45   one point per day, labelled by date     "Sep 5"
 *   up to 120  one point per 7 days from the start     "Jun 13"
 *   longer     one point per calendar month            "Jun"
 *
 * The monthly tier is not reachable through the range control today — its
 * longest window is MAX_CUSTOM_DAYS (92) — and exists so the rule stays
 * correct if that cap is ever raised, rather than silently falling back to
 * thirteen unreadable weekly labels on a year of data.
 */
final class AnalyticsChartBuckets
{
    public const GRANULARITY_DAY = 'day';
    public const GRANULARITY_WEEK = 'week';
    public const GRANULARITY_MONTH = 'month';

    /** Up to this many dates: weekday labels. */
    public const WEEKDAY_LABEL_MAX_DAYS = 14;

    /** Up to this many dates: one point per day. */
    public const DAILY_MAX_DAYS = 45;

    /** Up to this many dates: one point per 7 days. */
    public const WEEKLY_MAX_DAYS = 120;

    /**
     * @param  array<int, string>  $names  the series to carry, in legend order
     * @return array{granularity: string, labels: array<int, string>, tooltips: array<int, string>, series: array<string, array<int, int>>}
     */
    public static function fromDailySeries(DailySeries $daily, array $names): array
    {
        $dates = array_values($daily->dates);
        $count = count($dates);

        $granularity = match (true) {
            $count <= self::DAILY_MAX_DAYS => self::GRANULARITY_DAY,
            $count <= self::WEEKLY_MAX_DAYS => self::GRANULARITY_WEEK,
            default => self::GRANULARITY_MONTH,
        };

        $groups = match ($granularity) {
            self::GRANULARITY_DAY => self::dailyGroups($dates),
            self::GRANULARITY_WEEK => self::weeklyGroups($dates),
            default => self::monthlyGroups($dates),
        };

        $labels = [];
        $tooltips = [];
        $series = array_fill_keys($names, []);

        foreach ($groups as $group) {
            $first = self::date($dates[$group[0]]);
            $last = self::date($dates[$group[count($group) - 1]]);

            $labels[] = match ($granularity) {
                self::GRANULARITY_DAY => $first->format($count <= self::WEEKDAY_LABEL_MAX_DAYS ? 'D j' : 'M j'),
                self::GRANULARITY_WEEK => $first->format('M j'),
                default => $first->format('M'),
            };

            $tooltips[] = $granularity === self::GRANULARITY_DAY
                ? $first->format('D, M j, Y')
                : self::span($first, $last);

            foreach ($names as $name) {
                $values = $daily->series[$name] ?? [];
                $sum = 0;

                foreach ($group as $position) {
                    $sum += (int) ($values[$position] ?? 0);
                }

                $series[$name][] = $sum;
            }
        }

        return [
            'granularity' => $granularity,
            'labels' => $labels,
            'tooltips' => $tooltips,
            'series' => $series,
        ];
    }

    /** @return array<int, array<int, int>> one group per date */
    private static function dailyGroups(array $dates): array
    {
        return array_map(static fn (int $position): array => [$position], array_keys($dates));
    }

    /**
     * Consecutive 7-date blocks from the first date. Every block but the
     * last is a full week, so no bar is visibly short because of where a
     * calendar week happened to begin; the tooltip names the exact dates,
     * which is what makes a shorter final block unambiguous.
     *
     * @return array<int, array<int, int>>
     */
    private static function weeklyGroups(array $dates): array
    {
        return array_chunk(array_keys($dates), 7);
    }

    /** @return array<int, array<int, int>> one group per calendar month */
    private static function monthlyGroups(array $dates): array
    {
        $groups = [];

        foreach ($dates as $position => $date) {
            $groups[substr($date, 0, 7)][] = $position;
        }

        return array_values($groups);
    }

    /** "Jun 13 – 19", or "Jun 27 – Jul 3" across a month, with the year only across a year. */
    private static function span(CarbonImmutable $first, CarbonImmutable $last): string
    {
        if ($first->equalTo($last)) {
            return $first->format('D, M j, Y');
        }

        if ($first->year !== $last->year) {
            return $first->format('M j, Y') . ' – ' . $last->format('M j, Y');
        }

        if ($first->month === $last->month) {
            return $first->format('M j') . ' – ' . $last->format('j, Y');
        }

        return $first->format('M j') . ' – ' . $last->format('M j, Y');
    }

    /**
     * A calendar date for formatting only. UTC is used as a neutral,
     * DST-free calendar: nothing is converted, so the date printed is
     * exactly the local date B5 bucketed.
     */
    private static function date(string $localDate): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $localDate, 'UTC');
    }
}
