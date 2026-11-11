<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceSummary;
use App\Models\BusinessGoogleLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Jobs\GoogleBusinessProfile\RefreshGoogleBusinessProfilePerformance;
use App\Jobs\GoogleBusinessProfile\SweepGoogleBusinessProfilePerformance;
use App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    /** The Refresh button (a forced read), as a full page. */
    private function load(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('customer.workspaces.businesses.gbp.performance', [$this->workspace->uid, $this->business->uid]), ['refresh' => 1]);
    }

    /** What the page itself sends after it has loaded, when no result exists yet (no click, no refresh flag). */
    private function autoLoad(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('customer.workspaces.businesses.gbp.performance', [$this->workspace->uid, $this->business->uid]), [], ['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function page(): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.gbp.index', [$this->workspace->uid, $this->business->uid]));
    }

    private function binding(): BusinessGoogleLocation
    {
        return BusinessGoogleLocation::query()->where('business_id', $this->business->id)->orderBy('id')->firstOrFail();
    }

    /** A workspace member who may view Google Business Profile but not manage it. */
    private function viewOnlyMember(): void
    {
        $member = $this->createCustomer()->user;
        $this->addMember($this->workspace, $member, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($member, ['view_google_business_profile']);
    }

    private function cacheKey(int $businessId, int $bindingId): string
    {
        return 'gbp-performance:v1:b' . $businessId . ':l' . $bindingId;
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

    public function test_a_page_view_never_calls_google_itself_and_asks_for_a_first_read_after_it_loads(): void
    {
        Http::fake();
        $this->fakeGoogle->clearCalls();

        $this->page()->assertOk()->assertSee('Loading your Google performance')->assertSee('data-auto="1"', false)->assertSee('Refresh Google performance');

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
        $this->assertStringContainsString('Last successful refresh: <strong>10 Nov 2026, 10:00 UTC</strong>', $html);
        $this->assertStringContainsString('Updated today', $html);
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

    public function test_the_hourly_call_budget_still_applies(): void
    {
        config(['google_business_profile.sync.max_calls_per_business_per_hour' => 1]);
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));

        $this->load()->assertOk()->assertSee('hourly limit');

        $this->assertCount(0, Http::recorded(), 'The token exchange used the only allowed call, so Google was never contacted.');
        $this->assertNull(Cache::get($this->cacheKey($this->business->id, $this->binding()->id)), 'A failed read stores no Google content.');
    }

    public function test_the_existing_profile_comparison_section_is_still_on_the_page(): void
    {
        $this->get(route('customer.workspaces.businesses.gbp.index', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()->assertSee('Refresh from Google')->assertSee('Google performance')->assertSee('Refresh required', false);
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

    // ------------------------------------------------------------------ automatic loading, cache, daily refresh

    public function test_the_first_visit_loads_automatically_then_later_visits_show_the_recent_result_without_calling_google(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), ['2026-10' => [['searchKeyword' => 'photo booth', 'insightsValue' => ['value' => '9']]]]);

        $this->page()->assertSee('Loading your Google performance');
        $this->autoLoad()->assertOk()->assertSee('data-kpi="visibility"', false)->assertSee('Updated today')->assertSee('photo booth');
        $callsAfterFirstRead = count(Http::recorded());

        $this->page()->assertOk()->assertSee('data-kpi="visibility"', false)->assertSee('Updated today')->assertSee('Last successful refresh')
            ->assertDontSee('Loading your Google performance')->assertDontSee('data-auto="1"', false);
        $this->autoLoad()->assertOk()->assertSee('data-kpi="visibility"', false);

        $this->assertSame($callsAfterFirstRead, count(Http::recorded()), 'Visits and repeated automatic requests make no further Google call.');
    }

    public function test_the_stored_copy_is_encrypted_scoped_short_lived_and_not_an_archive(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), ['2026-10' => [['searchKeyword' => 'very private search phrase', 'insightsValue' => ['value' => '9']]]]);
        $this->autoLoad()->assertOk();

        $key = $this->cacheKey($this->business->id, $this->binding()->id);
        $raw = Cache::get($key);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('very private search phrase', $raw, 'Encrypted at rest.');
        $this->assertStringNotContainsString('BUSINESS_IMPRESSIONS', $raw);

        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($raw), true);
        $this->assertSame(['fetched_at', 'series', 'keywords'], array_keys($payload), 'Only what Google returned: no totals, comparisons or scores are stored.');

        $this->travel(25)->hours();
        $this->assertNull(app(GoogleBusinessProfilePerformanceLoader::class)->cached($this->binding()), 'Nothing older than 24 hours is ever shown.');
    }

    public function test_data_from_an_earlier_day_is_labelled_older_and_expired_data_loads_again(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();

        $this->travel(14)->hours(); // next day, still inside the 24-hour window
        $this->page()->assertSee('Older data')->assertDontSee('Updated today');

        $this->travel(11)->hours(); // 25 hours after the read
        $this->page()->assertDontSee('data-kpi="visibility"', false)->assertSee('Loading your Google performance');
    }

    public function test_a_failed_refresh_keeps_the_old_numbers_labelled_as_not_current(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();

        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeGoogleApi([], [], 429);
        $this->travel(2)->hours();

        $this->load()->assertOk()->assertSee('The latest refresh failed')->assertSee('not current')->assertSee('Google is rate limiting')
            ->assertSee('Last successful refresh: <strong>10 Nov 2026, 10:00 UTC</strong>', false);
    }

    public function test_a_failed_first_read_is_shown_honestly_and_is_not_retried_by_every_visit(): void
    {
        $this->fakeGoogleApi([], [], 500);

        $this->autoLoad()->assertOk()->assertSee('could not be loaded')->assertSee('temporarily unavailable');
        $calls = count(Http::recorded());

        $this->page()->assertOk()->assertSee('could not be loaded')->assertDontSee('data-auto="1"', false);
        $this->autoLoad()->assertOk()->assertSee('could not be loaded');
        $this->assertSame($calls, count(Http::recorded()), 'The back-off stops automatic retries; only Refresh tries again.');
        $this->assertNull(Cache::get($this->cacheKey($this->business->id, $this->binding()->id)));
    }

    public function test_two_requests_for_one_connection_do_not_read_google_twice(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->assertTrue(app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class)->claimRefresh($this->binding()->connection));

        $this->autoLoad()->assertOk()->assertSee('already running');

        Http::assertNothingSent();
    }

    public function test_a_revoked_or_disconnected_account_shows_and_keeps_nothing(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();
        $key = $this->cacheKey($this->business->id, $this->binding()->id);
        $this->assertNotNull(Cache::get($key));

        $this->binding()->connection->forceFill(['state' => GoogleConnectionState::Revoked])->save();

        $this->page()->assertOk()->assertDontSee('data-kpi="visibility"', false);
        $this->assertNull(Cache::get($key), 'A revoked connection deletes what was read from Google.');

        $this->binding()->connection->forceFill(['state' => GoogleConnectionState::Active])->save();
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();
        $this->assertNotNull(Cache::get($key));

        $this->post(route('customer.workspaces.businesses.gbp.disconnect', [$this->workspace->uid, $this->business->uid]));
        $this->assertNull(Cache::get($key), 'Disconnecting deletes it too.');
    }

    public function test_unlinking_a_location_deletes_its_stored_performance(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();
        $binding = $this->binding();
        $key = $this->cacheKey($this->business->id, $binding->id);

        $this->post(route('customer.workspaces.businesses.gbp.unbind', [$this->workspace->uid, $this->business->uid]), ['binding_uid' => $binding->uid]);

        $this->assertNull(Cache::get($key));
    }

    public function test_locations_and_tenants_are_stored_and_shown_separately(): void
    {
        // A second linked location of the SAME business, with different numbers.
        $second = $this->createLocation($this->business, false, overrides: ['name' => 'Second Studio']);
        BusinessGoogleLocation::create([
            'business_google_connection_id' => $this->binding()->business_google_connection_id, 'business_id' => $this->business->id, 'business_location_id' => $second->id,
            'provider_account_resource_name' => 'accounts/A1', 'provider_location_resource_name' => 'locations/L2',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), ':fetchMultiDailyMetricsTimeSeries')) {
                return Http::response($this->dailyResponse($this->fullValues(str_contains($request->url(), 'locations/L2') ? ['WEBSITE_CLICKS' => [7, 7]] : [])));
            }

            return Http::response(['searchKeywordsCounts' => []]);
        });

        $html = $this->autoLoad()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Primary Location.*?data-kpi="website".*?>84<.*?Second Studio.*?data-kpi="website".*?>196</s', $html, 'Each location shows its own numbers (3 x 28 and 7 x 28 website clicks).');
        $keys = BusinessGoogleLocation::query()->where('business_id', $this->business->id)->pluck('id')->map(fn ($id) => $this->cacheKey($this->business->id, $id));
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertNotNull(Cache::get($key));
        }

        // Another business has nothing, and cannot see or trigger this one's.
        [$otherCustomer] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);
        $this->page()->assertNotFound();
        $this->assertNull(app(\App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceStore::class)->get($this->business->id + 1000, $this->binding()->id));
    }

    public function test_someone_who_may_only_view_sees_the_result_but_cannot_start_or_refresh_a_read(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();
        $calls = count(Http::recorded());

        $this->viewOnlyMember();

        $this->page()->assertOk()->assertSee('data-kpi="visibility"', false)->assertDontSee('Refresh Google performance')->assertDontSee('data-auto="1"', false);
        $this->load()->assertStatus(401);
        $this->autoLoad()->assertStatus(401);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_a_view_only_visit_with_nothing_loaded_calls_nothing(): void
    {
        Http::fake();
        $this->viewOnlyMember();

        $this->page()->assertOk()->assertSee('No Google performance has been read yet')->assertDontSee('data-auto="1"', false);

        Http::assertNothingSent();
    }

    public function test_the_cache_window_is_never_longer_than_24_hours_and_zero_stores_nothing(): void
    {
        $store = app(\App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceStore::class);

        foreach ([100, 'x', -3, 25, null] as $bad) {
            config(['google_business_profile.performance.cache_hours' => $bad]);
            $this->assertSame(24, $store->ttlHours(), json_encode($bad));
        }
        config(['google_business_profile.performance.cache_hours' => 6]);
        $this->assertSame(6, $store->ttlHours());

        config(['google_business_profile.performance.cache_hours' => 0]);
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk()->assertSee('data-kpi="visibility"', false);
        $this->assertNull(Cache::get($this->cacheKey($this->business->id, $this->binding()->id)));
    }

    public function test_the_daily_sweep_is_staggered_skips_fresh_locations_and_the_job_rechecks_everything(): void
    {
        Queue::fake();
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $loader = app(GoogleBusinessProfilePerformanceLoader::class);

        (new SweepGoogleBusinessProfilePerformance())->handle($loader);
        Queue::assertPushed(RefreshGoogleBusinessProfilePerformance::class, 1);
        $pushed = Queue::pushed(RefreshGoogleBusinessProfilePerformance::class)->first();
        $delay = now()->diffInSeconds($pushed->delay, false);
        $this->assertGreaterThanOrEqual(0, $delay);
        $this->assertLessThan(3600, $delay);

        // The job reads once, stores, and a second run (or a visit) does not read again.
        $manager = app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class);
        $entitlements = app(\App\Library\Entitlement\EntitlementManager::class);
        (new RefreshGoogleBusinessProfilePerformance($this->binding()->id))->handle($manager, $loader, $entitlements);
        $reads = count(Http::recorded());
        $this->assertGreaterThan(0, $reads);
        $this->assertNotNull($loader->cached($this->binding()));
        (new RefreshGoogleBusinessProfilePerformance($this->binding()->id))->handle($manager, $loader, $entitlements);
        $this->assertSame($reads, count(Http::recorded()));

        Queue::fake();
        (new SweepGoogleBusinessProfilePerformance())->handle($loader);
        Queue::assertNothingPushed();

        // 21 hours later the result is due again (the sweep refreshes daily).
        $this->travel(21)->hours();
        (new SweepGoogleBusinessProfilePerformance())->handle($loader);
        Queue::assertPushed(RefreshGoogleBusinessProfilePerformance::class, 1);
    }

    public function test_the_job_makes_no_call_for_an_inactive_connection_or_a_downgraded_business(): void
    {
        Http::fake();
        $loader = app(GoogleBusinessProfilePerformanceLoader::class);
        $manager = app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class);
        $entitlements = app(\App\Library\Entitlement\EntitlementManager::class);

        $this->binding()->connection->forceFill(['state' => GoogleConnectionState::Revoked])->save();
        (new RefreshGoogleBusinessProfilePerformance($this->binding()->id))->handle($manager, $loader, $entitlements);

        $this->binding()->connection->forceFill(['state' => GoogleConnectionState::Active])->save();
        \DB::table('businesses')->where('id', $this->business->id)->update(['status' => \App\Enums\Business\BusinessStatus::Draft->value]);
        (new RefreshGoogleBusinessProfilePerformance($this->binding()->id))->handle($manager, $loader, $entitlements);

        Http::assertNothingSent();
        $this->assertNull($loader->cached($this->binding()));
    }

    public function test_a_stale_profile_mirror_keeps_its_own_state_when_performance_loads(): void
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();

        $this->page()->assertSee('Updated today')->assertSee('Refresh required', false);
        $this->assertNull($this->binding()->mirror_fetched_at, 'Loading performance does not touch the profile mirror.');
    }

    public function test_view_as_never_starts_a_google_read(): void
    {
        Http::fake();
        app(\App\Library\ViewAs\ViewAsManager::class)->start($this->customer->user, $this->workspace->uid, $this->business->uid);

        $this->autoLoad();
        $this->load();

        Http::assertNothingSent();
        $this->assertNull(Cache::get($this->cacheKey($this->business->id, $this->binding()->id)));
    }

    public function test_the_performance_sweep_is_scheduled_once_a_day(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->description, 'SweepGoogleBusinessProfilePerformance'));

        $this->assertCount(1, $events);
        $this->assertSame('30 3 * * *', $events->first()->expression);
    }

    public function test_a_full_page_refresh_with_caching_off_shows_what_google_just_returned_and_starts_nothing_else(): void
    {
        config(['google_business_profile.performance.cache_hours' => 0]);
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()), ['2026-10' => [['searchKeyword' => 'photo booth', 'insightsValue' => ['value' => '9']]]]);

        $response = $this->load()->assertOk();

        $response->assertSee('data-kpi="visibility"', false)->assertSee('photo booth')->assertSee('Updated today');
        $response->assertDontSee('data-auto="1"', false)->assertDontSee('Loading your Google performance');
        $this->assertStringContainsString('224', $response->getContent());
        $calls = count(Http::recorded());

        // Nothing was stored, so the next page view starts its own (single) automatic read, exactly as designed; the ordinary AJAX route still answers.
        $this->autoLoad()->assertOk()->assertSee('data-kpi="visibility"', false);
        $this->assertGreaterThan($calls, count(Http::recorded()));
    }

    // ------------------------------------------------------------------ every disconnect path purges the cache

    private function warmCache(): string
    {
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();
        $key = $this->cacheKey($this->business->id, $this->binding()->id);
        $this->assertNotNull(Cache::get($key));

        return $key;
    }

    /** A second, unrelated business with its own warm cache entry. */
    private function otherBusinessCache(): string
    {
        [, $other] = $this->entitledTenant();
        $location = $this->createLocation($other, true);
        $connection = $this->activeConnection($other);
        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id, 'business_id' => $other->id, 'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A9', 'provider_location_resource_name' => 'locations/L9',
        ]);
        $key = $this->cacheKey($other->id, $binding->id);
        Cache::put($key, 'other-business-entry', now()->addHour());

        return $key;
    }

    public function test_the_onboarding_disconnect_purges_the_cache(): void
    {
        $key = $this->warmCache();
        $otherKey = $this->otherBusinessCache();

        app(\App\Library\Business\GoogleOnboardingBridge::class)->disconnect($this->business, (int) $this->customer->user_id);

        $this->assertNull(Cache::get($key));
        $this->assertSame('other-business-entry', Cache::get($otherKey), 'Another business is untouched.');
    }

    public function test_the_account_closure_disconnect_purges_the_cache(): void
    {
        $key = $this->warmCache();
        $otherKey = $this->otherBusinessCache();
        $token = (string) \Illuminate\Support\Str::uuid();
        $id = \DB::table('platform_account_closures')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'user_uid' => (string) \Illuminate\Support\Str::uuid(), 'user_email' => 'closure@example.test',
            'requested_by_user_id' => 1, 'reason' => 'test', 'state' => 'running', 'claim_token' => $token, 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $closure = \App\Models\PlatformAccountClosure::query()->findOrFail($id);
        $manager = app(\App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager::class);

        $method = new \ReflectionMethod($manager, 'disconnectProviders');
        $method->setAccessible(true);
        $method->invoke($manager, $closure, [$this->business->id], 1);

        $this->assertNull(Cache::get($key));
        $this->assertSame('other-business-entry', Cache::get($otherKey));
    }

    public function test_revocation_purges_the_cache_and_repeated_cleanup_is_harmless(): void
    {
        $key = $this->warmCache();
        $otherKey = $this->otherBusinessCache();
        $manager = app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class);

        $connectionId = $this->binding()->business_google_connection_id;
        $connection = fn () => \App\Models\BusinessGoogleConnection::query()->findOrFail($connectionId);
        $userId = (int) $this->customer->user_id;

        $manager->revoke($connection());
        $this->assertNull(Cache::get($key));
        $this->assertSame('other-business-entry', Cache::get($otherKey));

        $manager->revoke($connection());
        $manager->disconnect($connection(), $userId);
        $manager->disconnect($connection(), $userId);
        $this->assertNull(Cache::get($key));
        $this->assertSame('other-business-entry', Cache::get($otherKey));
    }

    public function test_unbinding_one_location_purges_only_that_locations_cache(): void
    {
        $second = $this->createLocation($this->business, false, overrides: ['name' => 'Second Studio']);
        $secondBinding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $this->binding()->business_google_connection_id, 'business_id' => $this->business->id, 'business_location_id' => $second->id,
            'provider_account_resource_name' => 'accounts/A1', 'provider_location_resource_name' => 'locations/L2',
        ]);
        $this->fakeGoogleApi($this->dailyResponse($this->fullValues()));
        $this->autoLoad()->assertOk();
        $first = $this->binding();
        $this->assertNotNull(Cache::get($this->cacheKey($this->business->id, $first->id)));
        $this->assertNotNull(Cache::get($this->cacheKey($this->business->id, $secondBinding->id)));

        app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager::class)->unbind($first, (int) $this->customer->user_id);

        $this->assertNull(Cache::get($this->cacheKey($this->business->id, $first->id)));
        $this->assertNotNull(Cache::get($this->cacheKey($this->business->id, $secondBinding->id)));
    }

    // ------------------------------------------------------------------ a purge while Google is still answering

    /**
     * Google answers the daily-metrics request normally, but ONLY AFTER $duringTheRead has run: the lifecycle change lands in the
     * middle of the real read, exactly where the race is.
     */
    private function fakeGoogleWhileSomethingHappens(callable $duringTheRead): void
    {
        $daily = $this->dailyResponse($this->fullValues());
        $ran = false;

        Http::fake(function (Request $request) use ($daily, $duringTheRead, &$ran) {
            if (str_contains($request->url(), ':fetchMultiDailyMetricsTimeSeries')) {
                if (! $ran) {
                    $ran = true;
                    $duringTheRead();
                }

                return Http::response($daily);
            }

            return Http::response(['searchKeywordsCounts' => []]);
        });
    }

    private function connectionOf(BusinessGoogleLocation $binding): \App\Models\BusinessGoogleConnection
    {
        return \App\Models\BusinessGoogleConnection::query()->findOrFail($binding->business_google_connection_id);
    }

    public function test_unlinking_the_location_while_google_answers_caches_nothing(): void
    {
        $otherKey = $this->otherBusinessCache();
        $binding = $this->binding();
        $key = $this->cacheKey($this->business->id, $binding->id);
        $userId = (int) $this->customer->user_id;
        $this->fakeGoogleWhileSomethingHappens(fn () => app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager::class)->unbind(BusinessGoogleLocation::query()->findOrFail($binding->id), $userId));

        $this->autoLoad();

        $this->assertNull(Cache::get($key), 'The late Google response was not cached for a location that is no longer linked.');
        $this->assertSame('other-business-entry', Cache::get($otherKey));
    }

    public function test_disconnecting_while_google_answers_caches_nothing(): void
    {
        $otherKey = $this->otherBusinessCache();
        $binding = $this->binding();
        $key = $this->cacheKey($this->business->id, $binding->id);
        $userId = (int) $this->customer->user_id;
        $this->fakeGoogleWhileSomethingHappens(fn () => app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class)->disconnect($this->connectionOf($binding), $userId));

        $this->autoLoad();

        $this->assertNull(Cache::get($key));
        $this->assertSame('other-business-entry', Cache::get($otherKey));
    }

    public function test_the_onboarding_disconnect_while_google_answers_caches_nothing(): void
    {
        $binding = $this->binding();
        $key = $this->cacheKey($this->business->id, $binding->id);
        $this->fakeGoogleWhileSomethingHappens(fn () => app(\App\Library\Business\GoogleOnboardingBridge::class)->disconnect($this->business, (int) $this->customer->user_id));

        $this->autoLoad();

        $this->assertNull(Cache::get($key));
    }

    public function test_revocation_while_google_answers_caches_nothing(): void
    {
        $binding = $this->binding();
        $key = $this->cacheKey($this->business->id, $binding->id);
        $this->fakeGoogleWhileSomethingHappens(fn () => app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class)->revoke($this->connectionOf($binding)));

        $this->autoLoad();

        $this->assertNull(Cache::get($key));
    }

    public function test_a_reconnected_account_never_inherits_a_read_started_under_the_old_authorization(): void
    {
        $binding = $this->binding();
        $oldKey = $this->cacheKey($this->business->id, $binding->id);
        $userId = (int) $this->customer->user_id;
        $newBindingId = null;

        $this->fakeGoogleWhileSomethingHappens(function () use ($binding, $userId, &$newBindingId) {
            app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager::class)->disconnect($this->connectionOf($binding), $userId);
            // The owner reconnects Google and links the same location again.
            $this->connectionOf($binding)->forceFill(['state' => GoogleConnectionState::Active, 'refresh_token_encrypted' => 'new-authorization-token'])->save();
            $newBindingId = BusinessGoogleLocation::create([
                'business_google_connection_id' => $binding->business_google_connection_id, 'business_id' => $this->business->id, 'business_location_id' => $binding->business_location_id,
                'provider_account_resource_name' => 'accounts/A2', 'provider_location_resource_name' => 'locations/L1',
            ])->id;
        });

        $this->autoLoad();

        $this->assertNotNull($newBindingId);
        $this->assertNull(Cache::get($oldKey));
        $this->assertNull(Cache::get($this->cacheKey($this->business->id, $newBindingId)), 'The new link starts empty; the old authorization\'s response is not attached to it.');
    }

    public function test_a_read_that_nothing_interrupts_is_still_cached_and_repeated_cleanup_is_safe(): void
    {
        $this->fakeGoogleWhileSomethingHappens(fn () => null);
        $binding = $this->binding();
        $key = $this->cacheKey($this->business->id, $binding->id);

        $this->autoLoad()->assertOk()->assertSee('data-kpi="visibility"', false);
        $this->assertNotNull(Cache::get($key));

        $store = app(\App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceStore::class);
        $store->forget($this->business->id, $binding->id);
        $store->forget($this->business->id, $binding->id);
        $store->forgetConnection($this->connectionOf($binding));
        $store->forgetConnection($this->connectionOf($binding));
        $this->assertNull(Cache::get($key));

        // After a purge the next read starts a fresh generation and caches normally.
        $this->fakeGoogleWhileSomethingHappens(fn () => null);
        $this->autoLoad()->assertOk();
        $this->assertNotNull(Cache::get($key));
    }
}
