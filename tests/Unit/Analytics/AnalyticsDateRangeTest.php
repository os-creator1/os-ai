<?php

namespace Tests\Unit\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * B5 — contract §4 (presets, 92-day cap, strict parsing, half-open UTC
 * interval, independent DST-aware bucket boundaries).
 */
class AnalyticsDateRangeTest extends TestCase
{
    private const TZ = 'America/New_York';

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC');
    }

    public function test_default_is_the_last_30_local_days_ending_today(): void
    {
        $range = AnalyticsDateRange::fromInput([], self::TZ, $this->today());

        $this->assertSame(AnalyticsDateRange::PRESET_LAST_30_DAYS, $range->preset);
        $this->assertSame(30, $range->days());
        $this->assertSame('2026-05-17', $range->startLocal->format('Y-m-d'));
        $this->assertSame('2026-06-15', $range->endLocal->format('Y-m-d'));
        $this->assertSame('2026-05-17 04:00:00', $range->startUtc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 04:00:00', $range->endUtc->utc()->format('Y-m-d H:i:s'), 'endUtc is the start of the local day AFTER the final date.');
        $this->assertSame(config('app.timezone', 'UTC'), $range->startUtc->getTimezone()->getName(), 'Boundaries are expressed in the storage timezone.');
    }

    public function test_presets_resolve_to_7_30_and_90_local_days(): void
    {
        foreach (AnalyticsDateRange::PRESETS as $preset => $days) {
            $range = AnalyticsDateRange::preset($preset, self::TZ, $this->today());

            $this->assertSame($days, $range->days(), $preset);
            $this->assertSame('2026-06-15', $range->endLocal->format('Y-m-d'));
            $this->assertSame($preset, $range->cacheKey());
        }
    }

    public function test_custom_range_of_92_days_is_accepted_and_93_is_rejected(): void
    {
        $ok = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-02'], self::TZ);
        $this->assertSame(92, $ok->days());
        $this->assertSame('custom_2026-01-01_2026-04-02', $ok->cacheKey());

        $this->expectException(ValidationException::class);
        AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-03'], self::TZ);
    }

    public function test_reversed_custom_range_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-01'], self::TZ);
    }

    public function test_unparseable_dates_are_rejected_and_never_become_1970(): void
    {
        foreach (['not a date', '2026-13-45', '2026-02-30', '10/06/2026', ''] as $bad) {
            try {
                AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => $bad, 'end' => '2026-06-10'], self::TZ);
                $this->fail('Expected rejection of ' . var_export($bad, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('start', $exception->errors());
                $this->assertStringNotContainsString('1970', json_encode($exception->errors()));
            }
        }
    }

    public function test_unknown_preset_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        AnalyticsDateRange::fromInput(['range' => 'all_time'], self::TZ);
    }

    public function test_spring_forward_local_date_is_one_23_hour_bucket(): void
    {
        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-03-08', 'end' => '2026-03-08'], self::TZ);
        $buckets = $range->dailyBuckets();

        $this->assertCount(1, $buckets);
        $this->assertSame('2026-03-08 05:00:00', $buckets[0]['start']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 04:00:00', $buckets[0]['end']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(23 * 3600, $buckets[0]['end']->getTimestamp() - $buckets[0]['start']->getTimestamp());
    }

    public function test_fall_back_local_date_is_one_25_hour_bucket(): void
    {
        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-11-01', 'end' => '2026-11-01'], self::TZ);
        $buckets = $range->dailyBuckets();

        $this->assertCount(1, $buckets);
        $this->assertSame('2026-11-01 04:00:00', $buckets[0]['start']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 05:00:00', $buckets[0]['end']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(25 * 3600, $buckets[0]['end']->getTimestamp() - $buckets[0]['start']->getTimestamp());
    }

    public function test_bucket_boundaries_across_a_transition_are_not_fixed_86400_second_offsets(): void
    {
        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-03-07', 'end' => '2026-03-09'], self::TZ);
        $buckets = $range->dailyBuckets();

        $this->assertCount(3, $buckets);
        $lengths = array_map(fn (array $b) => $b['end']->getTimestamp() - $b['start']->getTimestamp(), $buckets);
        $this->assertSame([24 * 3600, 23 * 3600, 24 * 3600], $lengths);

        // Every bucket starts exactly where the previous one ends (no gaps,
        // no overlaps), and the last end equals the range end.
        $this->assertTrue($buckets[0]['end']->equalTo($buckets[1]['start']));
        $this->assertTrue($buckets[1]['end']->equalTo($buckets[2]['start']));
        $this->assertTrue($buckets[2]['end']->equalTo($range->endUtc));
    }

    public function test_positive_and_negative_offsets_produce_different_utc_intervals_for_the_same_local_date(): void
    {
        $tokyo = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-10'], 'Asia/Tokyo');
        $la = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-10'], 'America/Los_Angeles');

        $this->assertSame('2026-06-09 15:00:00', $tokyo->startUtc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10 15:00:00', $tokyo->endUtc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10 07:00:00', $la->startUtc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-11 07:00:00', $la->endUtc->utc()->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------
    // Results — calendar-month presets
    // ---------------------------------------------------------------

    public function test_this_month_runs_from_the_first_local_day_through_today(): void
    {
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, self::TZ, $this->today());

        $this->assertSame('2026-06-01', $range->startLocal->format('Y-m-d'));
        $this->assertSame('2026-06-15', $range->endLocal->format('Y-m-d'));
        $this->assertSame(15, $range->days());
        $this->assertSame('2026-06-01 04:00:00', $range->startUtc->utc()->format('Y-m-d H:i:s'), 'Local midnight of the 1st, in New York.');
        $this->assertSame('This month', $range->label());
    }

    public function test_this_month_on_the_first_is_a_single_day(): void
    {
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, self::TZ, CarbonImmutable::parse('2026-07-01 16:00:00', 'UTC'));

        $this->assertSame(1, $range->days());
        $this->assertSame('2026-07-01', $range->startLocal->format('Y-m-d'));
    }

    public function test_this_month_follows_the_business_calendar_not_utc(): void
    {
        // 03:00 UTC on 1 July is still 30 June in New York: "this month" is June.
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, self::TZ, CarbonImmutable::parse('2026-07-01 03:00:00', 'UTC'));

        $this->assertSame('2026-06-01', $range->startLocal->format('Y-m-d'));
        $this->assertSame('2026-06-30', $range->endLocal->format('Y-m-d'));
    }

    public function test_last_month_is_the_whole_previous_calendar_month(): void
    {
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_MONTH, self::TZ, $this->today());

        $this->assertSame('2026-05-01', $range->startLocal->format('Y-m-d'));
        $this->assertSame('2026-05-31', $range->endLocal->format('Y-m-d'));
        $this->assertSame(31, $range->days());
        $this->assertSame('2026-06-01 04:00:00', $range->endUtc->utc()->format('Y-m-d H:i:s'), 'Half-open: ends at local midnight of the 1st.');
        $this->assertSame('Last month', $range->label());
    }

    public function test_last_month_in_january_is_the_previous_december(): void
    {
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_MONTH, self::TZ, CarbonImmutable::parse('2026-01-20 12:00:00', 'UTC'));

        $this->assertSame('2025-12-01', $range->startLocal->format('Y-m-d'));
        $this->assertSame('2025-12-31', $range->endLocal->format('Y-m-d'));
    }

    public function test_last_month_after_a_31_day_month_never_overflows(): void
    {
        // subMonth() from 31 March would overflow into March again.
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_MONTH, self::TZ, CarbonImmutable::parse('2026-03-31 18:00:00', 'UTC'));

        $this->assertSame('2026-02-01', $range->startLocal->format('Y-m-d'));
        $this->assertSame('2026-02-28', $range->endLocal->format('Y-m-d'));
    }

    public function test_a_month_containing_a_dst_change_is_still_exactly_its_own_dates(): void
    {
        // March 2026 in New York holds the spring-forward day.
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_MONTH, self::TZ, CarbonImmutable::parse('2026-04-10 12:00:00', 'UTC'));

        $this->assertSame(31, $range->days());
        $this->assertCount(31, $range->dailyBuckets());
        $this->assertSame('2026-03-01 05:00:00', $range->startUtc->utc()->format('Y-m-d H:i:s'), 'EST before the change.');
        $this->assertSame('2026-04-01 04:00:00', $range->endUtc->utc()->format('Y-m-d H:i:s'), 'EDT after it.');
    }

    public function test_calendar_presets_carry_their_month_in_the_cache_key(): void
    {
        $lastDayOfJune = CarbonImmutable::parse('2026-06-30 16:00:00', 'UTC');
        $firstOfJuly = CarbonImmutable::parse('2026-07-01 16:00:00', 'UTC');

        $june = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, self::TZ, $lastDayOfJune);
        $july = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, self::TZ, $firstOfJuly);

        $this->assertSame('this_month_2026-06', $june->cacheKey());
        $this->assertSame('this_month_2026-07', $july->cacheKey());
        $this->assertNotSame($june->cacheKey(), $july->cacheKey(), 'June\'s figures can never be served as July\'s.');
        $this->assertSame('last_month_2026-05', AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_MONTH, self::TZ, $this->today())->cacheKey());
    }

    public function test_rolling_presets_keep_their_bare_cache_key(): void
    {
        foreach (array_keys(AnalyticsDateRange::PRESETS) as $preset) {
            $this->assertSame($preset, AnalyticsDateRange::preset($preset, self::TZ, $this->today())->cacheKey());
        }
    }

    public function test_every_selectable_preset_resolves(): void
    {
        foreach (AnalyticsDateRange::SELECTABLE_PRESETS as $preset) {
            if ($preset === AnalyticsDateRange::PRESET_CUSTOM) {
                continue;
            }

            $range = AnalyticsDateRange::preset($preset, self::TZ, $this->today());
            $this->assertGreaterThan(0, $range->days(), $preset);
            $this->assertLessThanOrEqual(AnalyticsDateRange::MAX_CUSTOM_DAYS, $range->days(), $preset);
        }
    }

    public function test_span_label_is_a_short_human_date_span(): void
    {
        $today = $this->today();

        $this->assertSame('May 17 – Jun 15', AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, self::TZ, $today)->spanLabel($today));
        $this->assertSame('Jun 15', AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-15', 'end' => '2026-06-15'], self::TZ)->spanLabel($today));
        $this->assertSame('Dec 20, 2025 – Jan 5, 2026', AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2025-12-20', 'end' => '2026-01-05'], self::TZ)->spanLabel($today));
        $this->assertSame('Mar 1 – Mar 31, 2025', AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2025-03-01', 'end' => '2025-03-31'], self::TZ)->spanLabel($today), 'A past year is named.');
    }
}
