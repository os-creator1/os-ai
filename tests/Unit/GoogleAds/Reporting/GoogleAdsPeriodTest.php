<?php

namespace Tests\Unit\GoogleAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Contract §9 — period resolution in the account time zone. Pure. */
class GoogleAdsPeriodTest extends TestCase
{
    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    /** @return array{0: string, 1: string} */
    private function range(string $key, string $zone, string $utcNow): array
    {
        $p = GoogleAdsPeriod::resolveIn($key, $zone, $this->at($utcNow));

        return [$p->fromDate(), $p->toDate()];
    }

    public function test_the_four_periods_resolve_to_inclusive_local_dates(): void
    {
        $now = '2026-10-04 12:00:00';

        $this->assertSame(['2026-09-28', '2026-10-04'], $this->range('last_7', 'America/New_York', $now));
        $this->assertSame(7, GoogleAdsPeriod::resolveIn('last_7', 'UTC', $this->at($now))->days());
        $this->assertSame(['2026-09-05', '2026-10-04'], $this->range('last_30', 'America/New_York', $now));
        $this->assertSame(30, GoogleAdsPeriod::resolveIn('last_30', 'UTC', $this->at($now))->days());
        $this->assertSame(['2026-10-01', '2026-10-04'], $this->range('this_month', 'UTC', $now));
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->range('previous_month', 'UTC', $now));
    }

    public function test_default_and_unknown_keys_resolve_to_last_30(): void
    {
        $now = $this->at('2026-10-04 12:00:00');

        $this->assertSame('last_30', GoogleAdsPeriod::resolveIn(null, 'UTC', $now)->key);
        $this->assertSame('last_30', GoogleAdsPeriod::resolveIn('last_90_days', 'UTC', $now)->key);
        $this->assertSame('last_30', GoogleAdsPeriod::resolveIn('', 'UTC', $now)->key);
    }

    public function test_time_zone_month_boundary_uses_the_account_local_date(): void
    {
        // 2026-11-01 02:00 UTC is still Oct 31 in Los Angeles but already Nov 1 in UTC.
        $now = '2026-11-01 02:00:00';

        $this->assertSame(['2026-10-01', '2026-10-31'], $this->range('this_month', 'America/Los_Angeles', $now));
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->range('previous_month', 'America/Los_Angeles', $now));
        $this->assertSame(['2026-11-01', '2026-11-01'], $this->range('this_month', 'UTC', $now));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->range('previous_month', 'UTC', $now));
    }

    public function test_previous_month_across_a_year_boundary_and_february(): void
    {
        $this->assertSame(['2026-12-01', '2026-12-31'], $this->range('previous_month', 'UTC', '2027-01-15 00:00:00'));
        $this->assertSame(['2027-02-01', '2027-02-28'], $this->range('previous_month', 'UTC', '2027-03-10 00:00:00'));
        $this->assertSame(28, GoogleAdsPeriod::resolveIn('previous_month', 'UTC', $this->at('2027-03-10 00:00:00'))->days());
    }

    public function test_an_invalid_time_zone_falls_back_to_utc(): void
    {
        $period = GoogleAdsPeriod::resolveIn('last_7', 'Mars/Olympus_Mons', $this->at('2026-10-04 23:30:00'));

        $this->assertSame('UTC', $period->timezone);
        $this->assertSame('2026-10-04', $period->toDate());
        $this->assertFalse(GoogleAdsPeriod::isValidTimezone(''));
        $this->assertTrue(GoogleAdsPeriod::isValidTimezone('Europe/London'));
    }

    public function test_comparison_windows(): void
    {
        $now = $this->at('2026-10-04 12:00:00');
        $pair = fn (GoogleAdsPeriod $p): array => [$p->fromDate(), $p->toDate()];

        $c7 = GoogleAdsPeriod::resolveIn('last_7', 'UTC', $now)->comparison();
        $this->assertSame(['2026-09-21', '2026-09-27'], $pair($c7));
        $this->assertSame(7, $c7->days());

        $this->assertSame(['2026-09-01', '2026-09-04'], $pair(GoogleAdsPeriod::resolveIn('this_month', 'UTC', $now)->comparison()));
        $this->assertSame(['2026-08-01', '2026-08-31'], $pair(GoogleAdsPeriod::resolveIn('previous_month', 'UTC', $now)->comparison()));

        // The 31st compared with a 30-day previous month is capped at its last day.
        $capped = GoogleAdsPeriod::resolveIn('this_month', 'UTC', $this->at('2026-10-31 12:00:00'))->comparison();
        $this->assertSame(['2026-09-01', '2026-09-30'], $pair($capped));
    }
}
