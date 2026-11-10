<?php

namespace App\Library\GoogleBusinessProfilePerformance;

use Carbon\CarbonImmutable;

/**
 * Turns Google's daily series into the four customer KPIs and an honest comparison with the preceding equal period.
 *
 * A total exists ONLY when Google reported every day of the period for every metric it is made of; otherwise it is null
 * ("not available"), never a partial sum and never zero. A change is shown only when both periods have a total. Pure.
 */
final class GoogleBusinessProfilePerformanceSummary
{
    public const DAYS = 28;

    /** Google reports with a delay of a few days; the period ends this many days before today so it is complete. */
    public const REPORTING_LAG_DAYS = 3;

    private const KPIS = [
        'visibility' => ['Search & Maps visibility', GoogleBusinessProfilePerformanceReader::IMPRESSION_METRICS],
        'website' => ['Website clicks', ['WEBSITE_CLICKS']],
        'calls' => ['Call clicks', ['CALL_CLICKS']],
        'directions' => ['Direction requests', ['BUSINESS_DIRECTION_REQUESTS']],
    ];

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, previous_start: CarbonImmutable, previous_end: CarbonImmutable} */
    public static function periods(CarbonImmutable $today): array
    {
        $end = $today->startOfDay()->subDays(self::REPORTING_LAG_DAYS);
        $start = $end->subDays(self::DAYS - 1);
        $previousEnd = $start->subDay();

        return ['start' => $start, 'end' => $end, 'previous_start' => $previousEnd->subDays(self::DAYS - 1), 'previous_end' => $previousEnd];
    }

    /**
     * @param  array<string, array<string, int>>  $series  metric => [Y-m-d => value], as read
     * @return array<int, array{key: string, label: string, current: ?int, previous: ?int, delta: ?int, percent: ?float, comparable: bool}>
     */
    public static function kpis(array $series, array $periods): array
    {
        $rows = [];

        foreach (self::KPIS as $key => [$label, $metrics]) {
            $current = self::total($series, $metrics, $periods['start'], $periods['end']);
            $previous = self::total($series, $metrics, $periods['previous_start'], $periods['previous_end']);
            $comparable = $current !== null && $previous !== null;
            $delta = $comparable ? $current - $previous : null;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'current' => $current,
                'previous' => $previous,
                'delta' => $delta,
                // A percentage of nothing is undefined, so it is not invented.
                'percent' => $comparable && $previous > 0 ? round($delta / $previous * 100, 1) : null,
                'comparable' => $comparable,
            ];
        }

        return $rows;
    }

    /** @param array<int, string> $metrics */
    private static function total(array $series, array $metrics, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $sum = 0;

        foreach ($metrics as $metric) {
            $days = $series[$metric] ?? null;

            if ($days === null) {
                return null;
            }

            for ($day = $start; $day <= $end; $day = $day->addDay()) {
                $key = $day->toDateString();

                if (! array_key_exists($key, $days)) {
                    return null;
                }

                $sum += $days[$key];
            }
        }

        return $sum;
    }

    /**
     * Highest first: exact counts by size, then the terms Google only gave a "fewer than N" threshold for.
     *
     * @param  array<int, array{term: string, value: ?int, threshold: ?int}>  $terms
     * @return array<int, array{term: string, value: ?int, threshold: ?int}>
     */
    public static function topTerms(array $terms, int $limit = 10): array
    {
        usort($terms, function (array $a, array $b): int {
            return [$a['value'] === null, -($a['value'] ?? $a['threshold'] ?? 0), $a['term']] <=> [$b['value'] === null, -($b['value'] ?? $b['threshold'] ?? 0), $b['term']];
        });

        return array_slice($terms, 0, $limit);
    }
}
