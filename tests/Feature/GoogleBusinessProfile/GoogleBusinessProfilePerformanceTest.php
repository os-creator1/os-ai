<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceSummary;
use App\Models\BusinessGoogleLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * Performance Insights V1: a manual, read-only load of Google's own daily metrics and monthly search keywords. All Google
 * responses here are mocked; nothing reaches a real Google host.
 */
class GoogleBusinessProfilePerformanceTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    private const HOST = 'businessprofileperformance.googleapis.com';

    private $workspace;

    private $business;

    private $customer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-10 10:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-11-10 10:00:00');
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeGoogleClient();
        $this->fakeGoogle->accessTokenToIssue = 'ya29.secret-access-token';

        [$this->customer, $this->business, $this->workspace] = $this->entitledTenant();
        $location = $this->createLocation($this->business, true);
        $connection = $this->activeConnection($this->business);
        BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id, 'business_id' => $this->business->id, 'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1', 'provider_location_resource_name' => 'locations/L1',
        ]);
        $this->authenticateAsCustomer($this->customer);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function load(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('customer.workspaces.businesses.gbp.performance', [$this->workspace->uid, $this->business->uid]));
    }

    /**
     * Daily series for the 56 days 2026-09-13 .. 2026-11-07. $values: metric => [previous-period value, current-period value]
     * (null = the metric is absent). $skip: metric => dates Google did not report.
     */
    private function dailyResponse(array $values, array $skip = []): array
    {
        $series = [];

        foreach ($values as $metric => $pair) {
            if ($pair === null) {
                continue;
            }

            $dated = [];
            for ($day = CarbonImmutable::parse('2026-09-13'); $day <= CarbonImmutable::parse('2026-11-07'); $day = $day->addDay()) {
                if (in_array($day->toDateString(), $skip[$metric] ?? [], true)) {
                    continue;
                }
                $value = $day < CarbonImmutable::parse('2026-10-11') ? $pair[0] : $pair[1];
                $row = ['date' => ['year' => $day->year, 'month' => $day->month, 'day' => $day->day]];
                if ($value !== 0) {
                    $row['value'] = (string) $value;
                }
                $dated[] = $row;
            }
            $series[] = ['dailyMetric' => $metric, 'timeSeries' => ['datedValues' => $dated]];
        }

        return ['multiDailyMetricTimeSeries' => [['dailyMetricTimeSeries' => $series]]];
    }

    private function fullValues(array $overrides = []): array
    {
        return array_merge([
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => [1, 2], 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => [1, 2],
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => [1, 2], 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => [1, 2],
            'WEBSITE_CLICKS' => [3, 3], 'CALL_CLICKS' => [1, 2], 'BUSINESS_DIRECTION_REQUESTS' => [2, 0],
        ], $overrides);
    }

    private function fakeGoogleApi(array $daily, array $keywordsByMonth = [], int $status = 200): void
    {
        Http::fake(function (Request $request) use ($daily, $keywordsByMonth, $status) {
            if ($status !== 200) {
                return Http::response(['error' => ['message' => 'secret provider text', 'status' => 'X']], $status);
            }
            if (str_contains($request->url(), ':fetchMultiDailyMetricsTimeSeries')) {
                return Http::response($daily);
            }
            preg_match('/monthlyRange\.startMonth\.year=(\d+)&monthlyRange\.startMonth\.month=(\d+)/', $request->url(), $m);

            return Http::response(['searchKeywordsCounts' => $keywordsByMonth[sprintf('%04d-%02d', $m[1] ?? 0, $m[2] ?? 0)] ?? []]);
        });
    }

    public function test_an_ordinary_page_view_makes_no_google_request_and_offers_a_manual_load(): void
    {
        Http::fake();
        $this->fakeGoogle->clearCalls();

        $response = $this->get(route('customer.workspaces.businesses.gbp.index', [$this->workspace->uid, $this->business->uid]));

        $response->assertOk()->assertSee('Load Google performance')->assertSee('Not loaded yet');
        Http::assertNothingSent();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeRefreshToken'));
    }

    public function test_it_requests_the_right_metrics_and_dates_and_only_with_get(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), ['2026-10' => [['searchKeyword' => 'photo booth', 'insightsValue' => ['value' => '9']]]]);

        $this->load()->assertOk();

        $recorded = Http::recorded();
        $this->assertGreaterThanOrEqual(2, count($recorded));
        foreach ($recorded as [$request]) {
            $this->assertSame('GET', $request->method(), 'No write request is ever sent to Google.');
            $this->assertSame(self::HOST, parse_url($request->url(), PHP_URL_HOST));
        }

        $daily = urldecode(collect($recorded)->map(fn ($pair) => $pair[0]->url())->first(fn ($url) => str_contains($url, 'fetchMultiDailyMetricsTimeSeries')));
        $this->assertStringContainsString('/v1/locations/L1:fetchMultiDailyMetricsTimeSeries', $daily);
        foreach (['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'WEBSITE_CLICKS', 'CALL_CLICKS', 'BUSINESS_DIRECTION_REQUESTS'] as $metric) {
            $this->assertStringContainsString('dailyMetrics=' . $metric, $daily);
        }
        // Previous 28 days start 2026-09-13; the current 28 days end 2026-11-07 (three days of reporting lag).
        $this->assertStringContainsString('dailyRange.startDate.year=2026&dailyRange.startDate.month=9&dailyRange.startDate.day=13', $daily);
        $this->assertStringContainsString('dailyRange.endDate.year=2026&dailyRange.endDate.month=11&dailyRange.endDate.day=7', $daily);

        $monthly = urldecode(collect($recorded)->map(fn ($pair) => $pair[0]->url())->first(fn ($url) => str_contains($url, 'searchkeywords/impressions/monthly')));
        $this->assertStringContainsString('monthlyRange.startMonth.year=2026&monthlyRange.startMonth.month=10', $monthly, 'The latest complete month.');
    }

    public function test_kpis_and_previous_period_comparison(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues(['CALL_CLICKS' => [1, 2]])));

        $html = $this->load()->assertOk()->getContent();

        // Visibility: 4 metrics x 28 days: previous 112, current 224 => +112 (+100%). Website unchanged. Calls 28 -> 56. Directions 56 -> 0 (zero is a real value).
        $this->assertMatchesRegularExpression('/data-kpi="visibility".*?224.*?\+112 \(\+100%\)/s', $html);
        $this->assertMatchesRegularExpression('/data-kpi="website".*?84.*?No change/s', $html);
        $this->assertMatchesRegularExpression('/data-kpi="calls".*?56.*?\+28 \(\+100%\)/s', $html);
        $this->assertMatchesRegularExpression('/data-kpi="directions".*?>0<.*?▼ −56 \(-100%\)/s', $html);
        $this->assertStringContainsString('11 Oct 2026', $html);
        $this->assertStringContainsString('7 Nov 2026', $html);
        $this->assertStringContainsString('Loaded 10 Nov 2026', $html);
    }

    public function test_an_incomplete_period_or_missing_metric_is_not_available_and_never_zero_or_compared(): void
    {
        // Calls: one previous-period day not reported. Directions: the metric is absent altogether.
        $values = $this->fullValues(['BUSINESS_DIRECTION_REQUESTS' => null]);
        $this->fakeGoogleApi($this->dailyResponse($values, ['CALL_CLICKS' => ['2026-09-20']]));

        $html = $this->load()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-kpi="directions".*?Not available/s', $html);
        $this->assertMatchesRegularExpression('/data-kpi="calls".*?56.*?No comparison/s', $html, 'The current period is complete, the previous one is not: shown without a change.');
        $callsBlock = explode('data-kpi="calls"', $html)[1] ?? '';
        $this->assertStringNotContainsString('data-role="change"', explode('data-kpi="directions"', $callsBlock)[0]);
    }

    public function test_no_data_at_all_shows_helpful_empty_states_not_zeros(): void
    {
        $this->fakeGoogleApi(['multiDailyMetricTimeSeries' => []]);

        $html = $this->load()->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'Not available'));
        $this->assertStringNotContainsString('data-role="change"', $html);
        $this->assertStringContainsString('Google returned no search terms for the last two months', $html);
        $this->assertStringNotContainsString('Google reported no activity', $html);
    }

    public function test_search_terms_with_exact_and_threshold_values_and_the_ranking_disclaimer(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), [
            '2026-10' => [
                ['searchKeyword' => 'booth near me', 'insightsValue' => ['threshold' => '15']],
                ['searchKeyword' => 'wedding photo booth boise', 'insightsValue' => ['value' => '40']],
                ['searchKeyword' => 'photo booth rental', 'insightsValue' => ['value' => '120']],
                ['searchKeyword' => 'no value term', 'insightsValue' => []],
            ],
        ]);

        $html = $this->load()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/photo booth rental.*?120 impressions.*?wedding photo booth boise.*?40 impressions.*?booth near me.*?fewer than 15 impressions/s', $html, 'Exact counts first, by size; threshold-only values last.');
        $this->assertStringNotContainsString('no value term', $html);
        $this->assertStringContainsString('October 2026', $html);
        $this->assertStringContainsString('not ranking positions', $html);
    }

    public function test_the_month_before_is_used_when_the_latest_month_has_no_terms(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), ['2026-09' => [['searchKeyword' => 'older term', 'insightsValue' => ['value' => '7']]]]);

        $html = $this->load()->assertOk()->getContent();

        $this->assertStringContainsString('older term', $html);
        $this->assertStringContainsString('September 2026', $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_google_failures_show_a_safe_message_and_never_provider_text(int $status, string $message): void
    {
        $this->fakeGoogleApi([], [], $status);

        $response = $this->load()->assertOk();

        $response->assertSee($message);
        $response->assertDontSee('secret provider text');
        $response->assertDontSee('data-kpi="visibility"', false);
    }

    public static function failures(): array
    {
        return ['401' => [401, 'Google denied access for this account.'], '403' => [403, 'Google denied access for this account.'], '429 quota' => [429, 'Google is rate limiting requests right now.']];
    }

    public function test_another_business_cannot_load_this_businesss_performance(): void
    {
        Http::fake();
        [$otherCustomer] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);

        $this->load()->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_nothing_google_sent_or_the_access_token_reaches_the_logs_or_the_ledger(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), ['2026-10' => [['searchKeyword' => 'very private search phrase', 'insightsValue' => ['value' => '9']]]]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . json_encode($event->context);
        });

        $this->load()->assertOk();

        $this->assertStringNotContainsString('ya29.secret-access-token', implode("\n", $logged));
        $this->assertStringNotContainsString('very private search phrase', implode("\n", $logged));
        $ledger = json_encode(\DB::table('business_google_operations')->get());
        $this->assertStringNotContainsString('ya29.secret-access-token', $ledger);
        $this->assertStringNotContainsString('very private search phrase', $ledger);
        $this->assertSame(1, \DB::table('business_google_operations')->where('operation_type', 'metrics_synced')->count(), 'The read is recorded in the operations ledger, by name only.');
    }

    public function test_nothing_is_stored_so_the_next_page_view_is_empty_again(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->load()->assertOk();

        $this->get(route('customer.workspaces.businesses.gbp.index', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()->assertSee('Not loaded yet')->assertDontSee('data-kpi="visibility"', false);
    }

    public function test_the_hourly_call_budget_still_applies(): void
    {
        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 1]);
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));

        $this->load()->assertOk()->assertSee('hourly limit');

        $this->assertCount(0, Http::recorded(), 'The token exchange used the only allowed call, so Google was never contacted.');
    }

    public function test_the_existing_profile_comparison_section_is_still_on_the_page(): void
    {
        $this->get(route('customer.workspaces.businesses.gbp.index', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()->assertSee('Refresh from Google')->assertSee('Google performance');
    }

    public function test_summary_periods_and_totals_are_exact(): void
    {
        $periods = GoogleBusinessProfilePerformanceSummary::periods(CarbonImmutable::parse('2026-11-10 23:59:00'));

        $this->assertSame(['2026-10-11', '2026-11-07', '2026-09-13', '2026-10-10'], [$periods['start']->toDateString(), $periods['end']->toDateString(), $periods['previous_start']->toDateString(), $periods['previous_end']->toDateString()]);
        $this->assertSame(28, (int) $periods['start']->diffInDays($periods['end']) + 1);
        $this->assertSame(28, (int) $periods['previous_start']->diffInDays($periods['previous_end']) + 1);
    }

    public function test_the_performance_reader_can_only_read_from_its_one_host(): void
    {
        $source = (string) file_get_contents(app_path('Library/GoogleBusinessProfilePerformance/GoogleBusinessProfilePerformanceReader.php'));

        foreach (['->post(', '->put(', '->patch(', '->delete(', '->send(', 'Http::post', 'Http::put', 'Http::patch', 'Http::delete'] as $write) {
            $this->assertStringNotContainsString($write, $source);
        }
        preg_match_all('/https:\/\/([a-z0-9.\-]+)/i', $source, $hosts);
        $this->assertSame([self::HOST], array_values(array_unique($hosts[1])));
    }
}
