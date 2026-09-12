<?php

namespace Tests\Unit\Analytics;

use App\DTO\Analytics\DailySeries;
use App\Library\Analytics\AnalyticsChartBuckets;
use App\Library\Analytics\AnalyticsDateRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Results — the chart axis must be readable: adaptive grouping, short
 * labels, the exact dates kept for the tooltip, and totals that never move.
 *
 * Plain PHPUnit, no database and no application: the bucketing is pure.
 */
class AnalyticsChartBucketsTest extends TestCase
{
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /** @return array{0: DailySeries, 1: array<int, int>} consecutive local dates starting at $start */
    private function series(string $start, int $days, ?callable $value = null): array
    {
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
        $dates = [];
        $values = [];

        for ($i = 0; $i < $days; $i++) {
            $dates[] = $cursor->addDays($i)->format('Y-m-d');
            $values[] = $value !== null ? $value($i) : ($i % 5) + 1;
        }

        return [new DailySeries($dates, ['n' => $values]), $values];
    }

    public function test_seven_days_are_one_point_per_day_labelled_by_weekday(): void
    {
        [$series] = $this->series('2026-09-07', 7);

        $chart = AnalyticsChartBuckets::fromDailySeries($series, ['n']);

        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_DAY, $chart['granularity']);
        $this->assertSame(['Mon 7', 'Tue 8', 'Wed 9', 'Thu 10', 'Fri 11', 'Sat 12', 'Sun 13'], $chart['labels']);
        $this->assertSame('Mon, Sep 7, 2026', $chart['tooltips'][0], 'The exact date survives in the tooltip.');
    }

    public function test_thirty_days_are_daily_points_labelled_by_month_and_day_never_iso(): void
    {
        [$series] = $this->series('2026-08-13', 30);

        $chart = AnalyticsChartBuckets::fromDailySeries($series, ['n']);

        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_DAY, $chart['granularity']);
        $this->assertCount(30, $chart['labels']);
        $this->assertSame('Aug 13', $chart['labels'][0]);
        $this->assertSame('Sep 1', $chart['labels'][19]);
        $this->assertSame('Sep 11', $chart['labels'][29]);

        foreach ($chart['labels'] as $label) {
            $this->assertDoesNotMatchRegularExpression(self::ISO_DATE, $label, 'No axis label is a full YYYY-MM-DD date.');
        }
    }

    public function test_ninety_days_are_grouped_into_consecutive_weeks_with_exact_spans_in_the_tooltip(): void
    {
        [$series, $values] = $this->series('2026-06-13', 90);

        $chart = AnalyticsChartBuckets::fromDailySeries($series, ['n']);

        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_WEEK, $chart['granularity']);
        $this->assertCount(13, $chart['labels'], '90 days is twelve full weeks and one six-day block.');
        $this->assertSame('Jun 13', $chart['labels'][0]);
        $this->assertSame('Jun 13 – 19, 2026', $chart['tooltips'][0]);
        $this->assertSame('Jun 27 – Jul 3, 2026', $chart['tooltips'][2], 'A week crossing a month names both months.');
        $this->assertSame('Sep 5 – 10, 2026', $chart['tooltips'][12], 'The final, shorter block states its own dates.');
        $this->assertSame(array_sum(array_slice($values, 0, 7)), $chart['series']['n'][0]);
    }

    public function test_a_long_series_groups_by_calendar_month_with_month_labels(): void
    {
        [$series] = $this->series('2026-06-01', 183);

        $chart = AnalyticsChartBuckets::fromDailySeries($series, ['n']);

        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_MONTH, $chart['granularity']);
        $this->assertSame(['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'], $chart['labels']);
        $this->assertSame('Jun 1 – 30, 2026', $chart['tooltips'][0]);
    }

    public function test_grouping_never_changes_a_total_at_any_granularity(): void
    {
        foreach ([7 => '2026-09-05', 30 => '2026-08-13', 45 => '2026-07-28', 46 => '2026-07-27', 90 => '2026-06-13', 120 => '2026-05-14', 200 => '2026-02-23'] as $days => $start) {
            [$series, $values] = $this->series($start, $days, fn (int $i): int => ($i * 7) % 11);

            $chart = AnalyticsChartBuckets::fromDailySeries($series, ['n']);

            $this->assertSame(array_sum($values), array_sum($chart['series']['n']), "{$days} days: grouped total equals daily total.");
            $this->assertCount(count($chart['labels']), $chart['series']['n']);
            $this->assertCount(count($chart['labels']), $chart['tooltips']);
        }
    }

    public function test_granularity_boundaries_are_exact(): void
    {
        $granularity = fn (int $days): string => AnalyticsChartBuckets::fromDailySeries($this->series('2026-01-01', $days)[0], ['n'])['granularity'];

        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_DAY, $granularity(AnalyticsChartBuckets::DAILY_MAX_DAYS));
        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_WEEK, $granularity(AnalyticsChartBuckets::DAILY_MAX_DAYS + 1));
        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_WEEK, $granularity(AnalyticsChartBuckets::WEEKLY_MAX_DAYS));
        $this->assertSame(AnalyticsChartBuckets::GRANULARITY_MONTH, $granularity(AnalyticsChartBuckets::WEEKLY_MAX_DAYS + 1));

        // The longest window the range control can produce is weekly: the
        // monthly tier is not reachable today, and this proves it.
        $this->assertLessThanOrEqual(AnalyticsChartBuckets::WEEKLY_MAX_DAYS, AnalyticsDateRange::MAX_CUSTOM_DAYS);
    }

    public function test_a_week_spanning_a_dst_change_labels_every_local_day_exactly_once(): void
    {
        // US DST began on 2026-03-08. The dates are already Business-local,
        // so the axis must show each of the seven days once — no repeated
        // or missing day where the clock jumped.
        [$series] = $this->series('2026-03-05', 7);

        $chart = AnalyticsChartBuckets::fromDailySeries($series, ['n']);

        $this->assertSame(['Thu 5', 'Fri 6', 'Sat 7', 'Sun 8', 'Mon 9', 'Tue 10', 'Wed 11'], $chart['labels']);
        $this->assertSame('Sun, Mar 8, 2026', $chart['tooltips'][3]);
    }

    public function test_series_are_carried_in_the_order_requested(): void
    {
        $daily = new DailySeries(['2026-09-01', '2026-09-02'], ['incoming' => [1, 2], 'accepted' => [3, 4], 'outgoing' => [9, 9]]);

        $chart = AnalyticsChartBuckets::fromDailySeries($daily, ['incoming', 'accepted']);

        $this->assertSame(['incoming', 'accepted'], array_keys($chart['series']));
        $this->assertSame([3, 4], $chart['series']['accepted']);
    }
}
