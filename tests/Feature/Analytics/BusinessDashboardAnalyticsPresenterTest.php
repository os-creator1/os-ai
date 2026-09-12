<?php

namespace Tests\Feature\Analytics;

use App\DTO\Analytics\AutomationKpis;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Models\Business;
use App\Repositories\Contracts\BusinessRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 4 §4.2–§4.6, §18 #39–#43, #45, #55, #56 — the
 * Analytics-owned comparison seam behind the Business Home headline row.
 * Composes B5's own messageKpis(), contactKpis() and automationKpis(), once
 * per range.
 *
 * H-3 generalized the two windows. The current one is whatever the customer
 * selected — any Results preset or a custom range of up to 92 local dates —
 * and defaults to This month; the previous one is always the SAME NUMBER of
 * Business-local calendar dates immediately before it. The seam still writes
 * no KPI formula and no timezone arithmetic of its own.
 */
class BusinessDashboardAnalyticsPresenterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private const TZ = 'America/New_York';

    protected function setUp(): void
    {
        parent::setUp();

        // 11:00 in New York on 10 Sep 2026: the contract's own example window.
        $this->freeze('2026-09-10 15:00:00');
    }

    // =================================================================
    // #38, #39 — the two windows
    // =================================================================

    public function test_the_default_window_is_this_month_and_the_previous_one_is_the_equal_length_window_before_it(): void
    {
        ['current' => $current, 'previous' => $previous] = BusinessDashboardAnalyticsPresenter::ranges(self::TZ);

        $this->assertSame(AnalyticsDateRange::PRESET_THIS_MONTH, $current->preset);
        $this->assertSame(BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET, $current->preset, 'Home opens on the month the customer is living in (H-3).');
        $this->assertSame('2026-09-01', $current->startLocal->format('Y-m-d'));
        $this->assertSame('2026-09-10', $current->endLocal->format('Y-m-d'));

        $this->assertSame(AnalyticsDateRange::PRESET_CUSTOM, $previous->preset);
        $this->assertSame('2026-08-22', $previous->startLocal->format('Y-m-d'));
        $this->assertSame('2026-08-31', $previous->endLocal->format('Y-m-d'));
        $this->assertSame(10, $current->days());
        $this->assertSame(10, $previous->days());

        // Adjacent half-open windows: no gap, no overlap.
        $this->assertTrue($previous->endUtc->equalTo($current->startUtc));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: string, 4: int}>
     */
    public static function selectedWindows(): array
    {
        // [frozen now (UTC), the customer's selection, previous start, previous end, local dates]
        return [
            'this month' => ['2026-09-10 15:00:00', ['range' => 'this_month'], '2026-08-22', '2026-08-31', 10],
            'last month' => ['2026-09-10 15:00:00', ['range' => 'last_month'], '2026-07-01', '2026-07-31', 31],
            'last 7 days' => ['2026-09-10 15:00:00', ['range' => 'last_7_days'], '2026-08-28', '2026-09-03', 7],
            'last 30 days' => ['2026-09-10 15:00:00', ['range' => 'last_30_days'], '2026-07-13', '2026-08-11', 30],
            'last 90 days' => ['2026-09-10 15:00:00', ['range' => 'last_90_days'], '2026-03-15', '2026-06-12', 90],
            'a custom fortnight' => ['2026-09-10 15:00:00', ['range' => 'custom', 'start' => '2026-07-02', 'end' => '2026-07-15'], '2026-06-18', '2026-07-01', 14],
            'a single day' => ['2026-09-10 15:00:00', ['range' => 'custom', 'start' => '2026-09-07', 'end' => '2026-09-07'], '2026-09-06', '2026-09-06', 1],
            'a custom range at the 92-day maximum' => ['2026-09-10 15:00:00', ['range' => 'custom', 'start' => '2026-06-01', 'end' => '2026-08-31'], '2026-03-01', '2026-05-31', 92],
            'this month in January' => ['2027-01-05 16:00:00', ['range' => 'this_month'], '2026-12-27', '2026-12-31', 5],
            'a custom range across a year boundary' => ['2027-01-20 16:00:00', ['range' => 'custom', 'start' => '2027-01-01', 'end' => '2027-01-10'], '2026-12-22', '2026-12-31', 10],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('selectedWindows')]
    public function test_any_selected_window_is_compared_with_an_equal_length_window_immediately_before_it(string $now, array $input, string $previousStart, string $previousEnd, int $days): void
    {
        $this->freeze($now);
        $selected = AnalyticsDateRange::fromInput($input, self::TZ);

        ['current' => $current, 'previous' => $previous] = BusinessDashboardAnalyticsPresenter::ranges(self::TZ, null, $selected);

        $this->assertSame($selected, $current, 'The window the customer selected is used exactly as Results built it.');
        $this->assertSame($days, $current->days());
        $this->assertSame($days, $previous->days(), 'The previous window covers the same number of local dates.');
        $this->assertSame($previousStart, $previous->startLocal->format('Y-m-d'));
        $this->assertSame($previousEnd, $previous->endLocal->format('Y-m-d'));
        $this->assertSame($current->startLocal->subDay()->format('Y-m-d'), $previous->endLocal->format('Y-m-d'));
        $this->assertTrue($previous->endUtc->equalTo($current->startUtc), 'Adjacent half-open windows.');
        $this->assertNotSame(
            $current->cacheKey(),
            $previous->cacheKey(),
            'Two windows of one view never share a cache identity.',
        );
    }

    // =================================================================
    // #40 — DST
    // =================================================================

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function dstCases(): array
    {
        // [frozen now (UTC), label, current window hours, previous window hours]
        return [
            'current spans spring-forward (8 Mar 2026)' => ['2026-03-20 15:00:00', 'spring-current', 30 * 24 - 1, 30 * 24],
            'previous spans spring-forward' => ['2026-04-10 15:00:00', 'spring-previous', 30 * 24, 30 * 24 - 1],
            'current spans fall-back (1 Nov 2026)' => ['2026-11-15 15:00:00', 'fall-current', 30 * 24 + 1, 30 * 24],
            'previous spans fall-back' => ['2026-12-05 15:00:00', 'fall-previous', 30 * 24, 30 * 24 + 1],
        ];
    }

    #[DataProvider('dstCases')]
    public function test_both_windows_cover_thirty_local_dates_across_dst_with_correct_half_open_bounds(string $now, string $label, int $currentHours, int $previousHours): void
    {
        $this->freeze($now);
        $selected = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, self::TZ);

        ['current' => $current, 'previous' => $previous] = BusinessDashboardAnalyticsPresenter::ranges(self::TZ, null, $selected);

        foreach (['current' => [$current, $currentHours], 'previous' => [$previous, $previousHours]] as $which => [$range, $hours]) {
            $this->assertCount(30, $range->dailyBuckets(), "{$label}: the {$which} window is 30 local dates.");

            // Each bound is that local date's midnight, in the storage timezone.
            $this->assertTrue(
                $range->startUtc->equalTo(CarbonImmutable::parse($range->startLocal->format('Y-m-d') . ' 00:00:00', self::TZ)),
                "{$label}: {$which} starts at local midnight.",
            );
            $this->assertTrue(
                $range->endUtc->equalTo(CarbonImmutable::parse($range->endLocal->format('Y-m-d') . ' 00:00:00', self::TZ)->addDay()),
                "{$label}: {$which} ends at the next local midnight (half-open).",
            );

            // A 23- or 25-hour day is still exactly one date: never 30 × 86 400 s.
            $this->assertSame($hours, (int) round(($range->endUtc->getTimestamp() - $range->startUtc->getTimestamp()) / 3600), "{$label}: {$which} hours.");
        }

        $this->assertTrue($previous->endUtc->equalTo($current->startUtc), "{$label}: adjacent windows.");
    }

    public function test_the_seam_uses_no_fixed_second_arithmetic_and_no_timezone_implementation_of_its_own(): void
    {
        $source = file_get_contents(app_path('Library/Analytics/BusinessDashboardAnalyticsPresenter.php'));
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        foreach (['86400', '86 400', 'subSeconds', 'addSeconds', 'subHours', 'setTimezone', 'strtotime', 'CONVERT_TZ', 'whereDate', 'DATE(', 'whereBetween', 'DB::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "{$forbidden} must not appear in the comparison seam.");
        }
    }

    // =================================================================
    // #41, #42, #43 — equality with B5, both periods
    // =================================================================

    public function test_messages_contacts_and_automation_runs_equal_the_b5_methods_for_both_periods(): void
    {
        [, $business] = $this->tenant(self::TZ);
        $u = $business->customer_id;
        $group = $this->group($business);
        $automation = $this->automation($business);

        // Current window: 12 Aug – 10 Sep; previous: 13 Jul – 11 Aug (local).
        $at = fn (string $local) => $this->utcFromLocal($local, self::TZ);

        foreach (['2026-08-12 00:00:00', '2026-09-01 12:00:00', '2026-09-10 23:59:59'] as $local) {
            $this->report($business, $u, ['created_at' => $at($local)]);
        }
        foreach (['2026-07-13 00:00:00', '2026-08-11 23:59:59'] as $local) {
            $this->report($business, $u, ['created_at' => $at($local)]);
        }
        $this->report($business, $u, ['created_at' => $at('2026-07-12 23:59:59')]); // before both windows
        $this->report($business, $u, ['created_at' => $at('2026-09-01 12:00:00'), 'customer_status' => 'Undelivered']);

        foreach (['2026-08-20 09:00:00', '2026-08-21 09:00:00'] as $local) {
            $contact = $this->contact($business, $group);
            \DB::table('contacts')->where('id', $contact->id)->update(['created_at' => $at($local)]);
        }
        $old = $this->contact($business, $group);
        \DB::table('contacts')->where('id', $old->id)->update(['created_at' => $at('2026-07-20 09:00:00')]);

        $contact = $this->contact($business, $group);
        \DB::table('contacts')->where('id', $contact->id)->update(['created_at' => '2020-01-01 00:00:00']); // outside both windows
        $this->execution($business, $automation, $contact, 'failed', 'contact_created', $at('2026-09-02 10:00:00'));
        $this->execution($business, $automation, $contact, 'succeeded', 'contact_created', $at('2026-07-30 10:00:00'));
        $this->execution($business, $automation, $contact, 'succeeded', 'contact_created', $at('2026-07-31 10:00:00'));

        // The fixtures above sit in the last-30-days window and the 30 dates
        // before it, so that is the window selected here.
        $comparison = app(BusinessDashboardAnalyticsPresenter::class)
            ->comparison($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, self::TZ));
        $queries = app(BusinessAnalyticsQueries::class);

        foreach (['current', 'previous'] as $period) {
            $range = $comparison[$period]['range'];

            $this->assertEquals($queries->messageKpis($business, $range)['kpis'], $comparison[$period]['messages'], "{$period}: MessageKpis");
            $this->assertEquals($queries->contactKpis($business, $range)['kpis'], $comparison[$period]['contacts'], "{$period}: ContactKpis");
            $this->assertEquals($queries->automationKpis($business, $range), $comparison[$period]['automations'], "{$period}: AutomationKpis");
        }

        $this->assertSame(4, $comparison['current']['messages']->outbound);
        $this->assertSame(2, $comparison['previous']['messages']->outbound);
        $this->assertSame(1, $comparison['current']['messages']->confirmedFailed);
        $this->assertSame(2, $comparison['current']['contacts']->newInRange);
        $this->assertSame(1, $comparison['previous']['contacts']->newInRange);
        $this->assertSame(1, $comparison['current']['automations']->executionsInRange);
        $this->assertSame(1, $comparison['current']['automations']->failed());
        $this->assertSame(2, $comparison['previous']['automations']->executionsInRange);
    }

    public function test_a_null_automation_period_stays_null_and_is_never_zeroed(): void
    {
        [, $business] = $this->tenant(self::TZ);

        $this->partialMock(BusinessAnalyticsQueries::class, function ($mock) {
            $mock->shouldReceive('automationKpis')->andReturnUsing(
                fn (Business $b, AnalyticsDateRange $range) => $range->preset === AnalyticsDateRange::PRESET_CUSTOM ? null : new AutomationKpis(2, ['succeeded' => 2], ['contact_created' => 2]),
            );
        });

        $comparison = app(BusinessDashboardAnalyticsPresenter::class)->comparison($business);

        $this->assertInstanceOf(AutomationKpis::class, $comparison['current']['automations']);
        $this->assertNull($comparison['previous']['automations']);

        // And null survives the cache round trip, never becoming zeros.
        $again = app(BusinessDashboardAnalyticsPresenter::class)->comparison($business);
        $this->assertNull($again['previous']['automations']);
    }

    // =================================================================
    // #45 — tenant-isolated cache
    // =================================================================

    public function test_the_comparison_cache_is_per_business_and_per_range_with_no_global_key(): void
    {
        [$customer, $businessA, $workspace] = $this->tenant(self::TZ);
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Other Venue', 'timezone' => self::TZ]));
        $u = $businessA->customer_id;
        $this->report($businessA, $u, ['created_at' => $this->utcFromLocal('2026-09-01 12:00:00', self::TZ)]);
        foreach (range(1, 3) as $i) {
            $this->report($businessB, $u, ['created_at' => $this->utcFromLocal('2026-09-01 12:00:00', self::TZ)]);
        }

        $seam = app(BusinessDashboardAnalyticsPresenter::class);

        $this->assertSame(1, $seam->comparison($businessA)['current']['messages']->outbound);
        $this->assertSame(3, $seam->comparison($businessB)['current']['messages']->outbound, 'Business B is never served Business A\'s comparison.');

        ['current' => $current, 'previous' => $previous] = BusinessDashboardAnalyticsPresenter::ranges(self::TZ);

        foreach ([$businessA, $businessB] as $business) {
            $currentKey = BusinessDashboardAnalyticsPresenter::cacheKey($business, $current);
            $previousKey = BusinessDashboardAnalyticsPresenter::cacheKey($business, $previous);

            $this->assertNotSame($currentKey, $previousKey, 'Current and previous periods never share one entry.');
            $this->assertStringContainsString('_' . $business->id . '_', $currentKey);
            $this->assertStringContainsString('this_month_2026-09', $currentKey);
            $this->assertStringContainsString('custom_2026-08-22_2026-08-31', $previousKey);
            $this->assertTrue(Cache::has($currentKey));
            $this->assertTrue(Cache::has($previousKey));
        }

        foreach (['dashboard_headlines', 'b5_dashboard_headlines', 'b5_dashboard_headlines_this_month', BusinessDashboardAnalyticsPresenter::CACHE_PREFIX . 'this_month'] as $global) {
            $this->assertFalse(Cache::has($global), "No global key: {$global}");
        }

        // A new row is invisible until B5's own TTL — the same strategy, not a second one.
        $this->report($businessA, $u, ['created_at' => $this->utcFromLocal('2026-09-02 12:00:00', self::TZ)]);
        $this->assertSame(1, $seam->comparison($businessA)['current']['messages']->outbound);
        $this->assertSame(300, BusinessAnalyticsPresenter::CACHE_TTL_SECONDS);
    }

    /**
     * H-3 — the customer can now choose the window, so the key must carry the
     * window itself. Two equally long windows, and a preset and the custom
     * range that happens to cover the same dates, are all separate entries.
     */
    public function test_one_business_never_serves_one_window_the_figures_of_another(): void
    {
        [, $business] = $this->tenant(self::TZ);
        $u = $business->customer_id;
        $this->report($business, $u, ['created_at' => $this->utcFromLocal('2026-07-10 12:00:00', self::TZ)]);
        $seam = app(BusinessDashboardAnalyticsPresenter::class);

        $july = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-07-01', 'end' => '2026-07-31'], self::TZ);
        $august = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-31'], self::TZ);
        $thisMonth = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, self::TZ);
        $sameDatesAsThisMonth = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-10'], self::TZ);

        $this->assertSame(1, $seam->comparison($business, $july)['current']['messages']->outbound);
        $this->assertSame(0, $seam->comparison($business, $august)['current']['messages']->outbound, 'August is never served July.');
        $this->assertSame(1, $seam->comparison($business, $july)['current']['messages']->outbound, 'And July is still July afterwards.');

        $keys = array_map(
            fn (AnalyticsDateRange $range) => BusinessDashboardAnalyticsPresenter::cacheKey($business, $range),
            [$july, $august, $thisMonth, $sameDatesAsThisMonth],
        );

        $this->assertSame($keys, array_values(array_unique($keys)), 'Every distinct window has its own entry.');
        $this->assertSame(31, $august->days(), 'Precondition: July and August are equally long.');
        $this->assertSame($july->days(), $august->days());
    }

    public function test_a_window_cached_before_local_midnight_is_never_paired_with_the_next_days_window(): void
    {
        [, $business] = $this->tenant(self::TZ);
        $seam = app(BusinessDashboardAnalyticsPresenter::class);

        $this->freeze('2026-09-11 03:59:00'); // 23:59, 10 Sep, in New York
        $before = $seam->comparison($business);

        $this->freeze('2026-09-11 04:01:00'); // 00:01, 11 Sep
        $after = $seam->comparison($business);

        $this->assertSame('2026-09-10', $before['current']['range']->endLocal->format('Y-m-d'));
        $this->assertSame('2026-09-11', $after['current']['range']->endLocal->format('Y-m-d'));
        $this->assertNotSame(
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $before['current']['range']),
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $after['current']['range']),
            'The preset name alone would collide across midnight; the bounds keep the windows apart.',
        );
    }

    // =================================================================
    // #55 — seam restraint
    // =================================================================

    public function test_the_seam_calls_only_message_contact_and_automation_kpis_once_per_range(): void
    {
        [, $business] = $this->tenant(self::TZ);

        $this->partialMock(BusinessAnalyticsQueries::class, function ($mock) {
            $mock->shouldReceive('messageKpis')->twice()->passthru();
            $mock->shouldReceive('contactKpis')->twice()->passthru();
            $mock->shouldReceive('automationKpis')->twice()->passthru();

            foreach (['messageVolumeSeries', 'contactGrowthSeries', 'campaignKpis', 'advisorKpis', 'campaignPerformancePage'] as $method) {
                $mock->shouldNotReceive($method);
            }
        });

        $sql = $this->capturedSql(fn () => app(BusinessDashboardAnalyticsPresenter::class)->comparison($business));

        $this->assertCount(6, $sql, 'Three B5 queries per range, two ranges: ' . implode(' | ', $sql));

        // A second call within the TTL costs nothing.
        $this->assertSame([], $this->capturedSql(fn () => app(BusinessDashboardAnalyticsPresenter::class)->comparison($business)));

        $source = file_get_contents(app_path('Library/Analytics/BusinessDashboardAnalyticsPresenter.php'));
        preg_match_all('/\$this->queries->(\w+)\(/', $source, $calls);
        $this->assertSame(['automationKpis', 'contactKpis', 'messageKpis'], $this->sorted(array_unique($calls[1])), 'The seam composes exactly three B5 methods.');
    }

    // =================================================================
    // #56 — B5 Results unchanged
    // =================================================================

    public function test_b5_results_behave_identically_before_and_after_the_seam_runs(): void
    {
        [, $business] = $this->tenant(self::TZ);
        $u = $business->customer_id;
        config(['opportunity.enabled' => true]);
        $this->report($business, $u, ['created_at' => $this->utcFromLocal('2026-09-01 12:00:00', self::TZ)]);
        $this->campaign($business);
        $group = $this->group($business);
        $this->contact($business, $group);

        $presenter = app(BusinessAnalyticsPresenter::class);
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, self::TZ);

        $overviewBefore = $presenter->buildOverview($business, $range);
        $seriesBefore = $presenter->buildSeries($business, $range);
        $campaignsBefore = $presenter->buildCampaignsPage($business, $range, 1);
        Cache::flush();

        app(BusinessDashboardAnalyticsPresenter::class)->comparison($business);

        $this->assertFalse(Cache::has('b5_analytics_' . $business->id . '_last_30_days'), 'The seam never writes B5\'s own cache entries.');
        $this->assertEquals($overviewBefore, $presenter->buildOverview($business, $range));
        $this->assertEquals($seriesBefore, $presenter->buildSeries($business, $range));

        $campaignsAfter = $presenter->buildCampaignsPage($business, $range, 1);
        $this->assertEquals($campaignsBefore['rows'], $campaignsAfter['rows']);
        $this->assertSame($campaignsBefore['paginator']->total(), $campaignsAfter['paginator']->total());
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }

    private function freeze(string $utc): void
    {
        $now = CarbonImmutable::parse($utc, 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }
}
