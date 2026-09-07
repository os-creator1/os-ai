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
}
