<?php

namespace Tests\Feature\Dashboards;

use App\DTO\Analytics\DailySeries;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Business Home §2.5 (Slice H-3) — Business performance.
 *
 * The period is the customer's own, through the SAME range infrastructure
 * Results uses: the same presets, the same 92-day custom maximum, the same
 * Business-local calendar semantics converted once into half-open
 * storage-timezone bounds. The comparison is always the equal-length window
 * immediately before the selected one.
 *
 * Exactly three figures render — new contacts, new conversations, messages
 * received — each from the seam that owns it, and nothing about sending,
 * providers, automations, leads, bookings or conversions appears at all.
 *
 * The chart is loaded afterwards by the browser from B5's own series endpoint
 * for the same range: the Home request itself computes no series.
 */
class BusinessHomePerformanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    private const TZ = 'America/New_York';

    protected function setUp(): void
    {
        parent::setUp();

        // 11:00 in New York on 10 Sep 2026.
        $this->freezeClock();
    }

    // =================================================================
    // The period selector
    // =================================================================

    public function test_home_opens_on_this_month(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Default Venue', 'Default Account');
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user);

        $this->assertSame(AnalyticsDateRange::PRESET_THIS_MONTH, BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET);
        $this->assertSame(AnalyticsDateRange::PRESET_THIS_MONTH, $band['range']->preset);
        $this->assertSame('2026-09-01', $band['range']->startLocal->format('Y-m-d'));
        $this->assertSame('2026-09-10', $band['range']->endLocal->format('Y-m-d'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string, 3: int}>
     */
    public static function periodCases(): array
    {
        return [
            'this month' => [['range' => AnalyticsDateRange::PRESET_THIS_MONTH], '2026-09-01', '2026-09-10', 10],
            'last month' => [['range' => AnalyticsDateRange::PRESET_LAST_MONTH], '2026-08-01', '2026-08-31', 31],
            'last 7 days' => [['range' => AnalyticsDateRange::PRESET_LAST_7_DAYS], '2026-09-04', '2026-09-10', 7],
            'last 30 days' => [['range' => AnalyticsDateRange::PRESET_LAST_30_DAYS], '2026-08-12', '2026-09-10', 30],
            'last 90 days' => [['range' => AnalyticsDateRange::PRESET_LAST_90_DAYS], '2026-06-13', '2026-09-10', 90],
            'custom' => [['range' => 'custom', 'start' => '2026-07-02', 'end' => '2026-07-20'], '2026-07-02', '2026-07-20', 19],
            'custom at the 92-day maximum' => [['range' => 'custom', 'start' => '2026-06-01', 'end' => '2026-08-31'], '2026-06-01', '2026-08-31', 92],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('periodCases')]
    public function test_every_supported_period_selects_its_own_window_and_an_equal_previous_one(array $query, string $start, string $end, int $days): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Period Venue', 'Period Account');
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user, $query);
        $current = $band['range'];
        $previous = $band['previousRange'];

        $this->assertFalse($band['rangeRejected']);
        $this->assertSame($start, $current->startLocal->format('Y-m-d'));
        $this->assertSame($end, $current->endLocal->format('Y-m-d'));
        $this->assertSame($days, $current->days());

        // The previous window: the same number of local dates, ending the day
        // before the selected one starts.
        $this->assertSame($days, $previous->days(), 'The previous period has the same number of local dates.');
        $this->assertSame($current->startLocal->subDay()->format('Y-m-d'), $previous->endLocal->format('Y-m-d'));
        $this->assertTrue($previous->endUtc->equalTo($current->startUtc), 'Adjacent half-open windows: no gap, no overlap.');
    }

    public function test_a_custom_range_longer_than_the_maximum_is_refused_and_the_default_renders(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Too Long Venue', 'Too Long Account');
        $this->authenticateAs($customer);
        $query = ['range' => 'custom', 'start' => '2026-05-01', 'end' => '2026-08-31']; // 123 local dates

        $band = $this->performance($customer->user, $query);

        $this->assertSame(92, AnalyticsDateRange::MAX_CUSTOM_DAYS);
        $this->assertTrue($band['rangeRejected']);
        $this->assertSame(AnalyticsDateRange::PRESET_THIS_MONTH, $band['range']->preset, 'A refused range is never approximated.');
        $this->assertSame(10, $band['range']->days());

        $rendered = $this->bandHtml($this->get(route('user.home', $query))->assertOk()->getContent(), 'headlines');
        $this->assertStringContainsString('data-role="range-rejected"', $rendered);
        $this->assertStringContainsString("That date range can't be used", html_entity_decode($rendered));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function unusableRanges(): array
    {
        return [
            'unknown preset' => [['range' => 'since_forever']],
            'reversed custom range' => [['range' => 'custom', 'start' => '2026-09-10', 'end' => '2026-09-01']],
            'custom range with no dates' => [['range' => 'custom']],
            'impossible date' => [['range' => 'custom', 'start' => '2026-02-31', 'end' => '2026-03-05']],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('unusableRanges')]
    public function test_an_unusable_range_never_reaches_the_figures(array $query): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Unusable Venue', 'Unusable Account');
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user, $query);

        $this->assertTrue($band['rangeRejected']);
        $this->assertSame(AnalyticsDateRange::PRESET_THIS_MONTH, $band['range']->preset);
        $this->get(route('user.home', $query))->assertOk();
    }

    public function test_the_previous_window_crosses_a_year_boundary_by_the_calendar(): void
    {
        $this->freezeClock('2027-01-05 16:00:00'); // 11:00 in New York, 5 Jan 2027
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Year Venue', 'Year Account');
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user);

        $this->assertSame('2027-01-01', $band['range']->startLocal->format('Y-m-d'));
        $this->assertSame('2027-01-05', $band['range']->endLocal->format('Y-m-d'));
        $this->assertSame('2026-12-27', $band['previousRange']->startLocal->format('Y-m-d'));
        $this->assertSame('2026-12-31', $band['previousRange']->endLocal->format('Y-m-d'));
        $this->assertSame(5, $band['previousRange']->days());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function dstCases(): array
    {
        // [frozen now (UTC), hours spanned by the 30 local dates of the window]
        return [
            'spring forward inside the window' => ['2026-03-20 15:00:00', 30 * 24 - 1],
            'fall back inside the window' => ['2026-11-15 16:00:00', 30 * 24 + 1],
        ];
    }

    #[DataProvider('dstCases')]
    public function test_a_daylight_saving_change_is_still_exactly_one_local_date(string $now, int $hours): void
    {
        $this->freezeClock($now);
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'DST Venue', 'DST Account');
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_30_DAYS]);
        $current = $band['range'];

        $this->assertCount(30, $current->dailyBuckets(), 'Thirty local dates, whatever their length in hours.');
        $this->assertSame($hours, (int) round(($current->endUtc->getTimestamp() - $current->startUtc->getTimestamp()) / 3600));
        $this->assertTrue(
            $current->startUtc->equalTo(CarbonImmutable::parse($current->startLocal->format('Y-m-d') . ' 00:00:00', self::TZ)),
            'The window opens at Business-local midnight.',
        );
        $this->assertSame(30, $band['previousRange']->days());
        $this->assertTrue($band['previousRange']->endUtc->equalTo($current->startUtc));
    }

    // =================================================================
    // The figures
    // =================================================================

    public function test_exactly_three_canonical_figures_render_in_kpi_order(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Three Venue', 'Three Account');
        $this->contactsAdded($business, 3, '2026-09-02');
        $this->conversationsStarted($business, 2, '2026-09-03');
        $this->receivedAt($business, 6, $this->localNoon('2026-09-04', $business->timezone));
        // None of these is a Business performance figure.
        $this->sent($business, 9, '2026-09-05');
        $this->sent($business, 4, '2026-09-05', 'Undelivered');
        $this->automationRuns($business, 5, '2026-09-06');
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user);

        $this->assertSame(
            ['new_contacts', 'new_conversations', 'messages_received'],
            array_map(fn ($headline) => $headline->key, $band['items']),
        );
        $this->assertSame(['3', '2', '6'], array_map(fn ($headline) => $headline->figure, $band['items']));

        $html = $this->home()->assertOk()->getContent();
        $this->assertSame(['new_contacts', 'new_conversations', 'messages_received'], $this->headlineKeys($html));

        foreach (['messages_sent', 'provider_accepted', 'confirmed_failed', 'automation_runs'] as $absent) {
            $this->assertNotContains($absent, $this->headlineKeys($html), "{$absent} is not a Business performance figure.");
        }

        // H-4 gave Automations its own band, so "runs" is now a truthful
        // word elsewhere on the page. What must stay true is that Business
        // PERFORMANCE carries none of these, and that the page as a whole
        // still invents no lead, booking, revenue or ranking.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(messages sent|provider accepted|confirmed failed|automation runs)\b/i',
            strip_tags($this->bandHtml($html, 'headlines')),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(leads?|bookings?|revenue|conversions?|google views|rankings?|SEO)\b/i',
            $this->mainText($html),
        );
    }

    public function test_each_figure_equals_its_own_seam_for_the_selected_window(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Seam Venue', 'Seam Account');
        // Inside Last month (1–31 Aug) and the 31 days before it (1–31 Jul).
        $this->contactsAdded($business, 5, '2026-08-04');
        $this->contactsAdded($business, 2, '2026-07-04');
        $this->conversationsStarted($business, 4, '2026-08-05');
        $this->conversationsStarted($business, 9, '2026-07-05');
        $this->receivedAt($business, 7, $this->localNoon('2026-08-06', $business->timezone));
        $this->receivedAt($business, 1, $this->localNoon('2026-07-06', $business->timezone));
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_MONTH]);
        $current = $band['range'];
        $previous = $band['previousRange'];
        $queries = app(BusinessAnalyticsQueries::class);
        $conversations = app(BusinessConversationReadModel::class);
        $figures = $this->byKey($band);

        $this->assertSame($queries->contactKpis($business, $current)['kpis']->newInRange, $figures['new_contacts']->comparison->current);
        $this->assertSame($queries->contactKpis($business, $previous)['kpis']->newInRange, $figures['new_contacts']->comparison->previous);
        $this->assertSame($conversations->startedCount($business, $current->startUtc, $current->endUtc), $figures['new_conversations']->comparison->current);
        $this->assertSame($conversations->startedCount($business, $previous->startUtc, $previous->endUtc), $figures['new_conversations']->comparison->previous);
        $this->assertSame($queries->messageKpis($business, $current)['kpis']->inbound, $figures['messages_received']->comparison->current);
        $this->assertSame($queries->messageKpis($business, $previous)['kpis']->inbound, $figures['messages_received']->comparison->previous);

        $this->assertSame([5, 2], [$figures['new_contacts']->comparison->current, $figures['new_contacts']->comparison->previous]);
        $this->assertSame([4, 9], [$figures['new_conversations']->comparison->current, $figures['new_conversations']->comparison->previous]);
        $this->assertSame([7, 1], [$figures['messages_received']->comparison->current, $figures['messages_received']->comparison->previous]);
    }

    public function test_messages_received_counts_what_came_in_and_never_outbound_volume(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Inbound Venue', 'Inbound Account');
        $this->sent($business, 12, '2026-09-02');
        $this->receivedAt($business, 3, $this->localNoon('2026-09-02', $business->timezone));
        $this->authenticateAs($customer);

        $figures = $this->byKey($this->performance($customer->user));

        $this->assertSame(3, $figures['messages_received']->comparison->current, 'Only what came in.');
        $this->assertSame('Received by this business, this month.', $figures['messages_received']->figureCaption);
    }

    public function test_conversations_come_through_the_slice_2b_read_model_alone(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Read Model Venue', 'Read Model Account');
        $this->conversationsStarted($business, 2, '2026-09-02');
        $this->authenticateAs($customer);

        $origins = [];
        DB::listen(function ($query) use (&$origins): void {
            if (! str_contains($query->sql, 'chat_boxes')) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (str_contains($file, '/app/')) {
                    $origins[] = substr($file, strpos($file, '/app/') + 1);
                    break;
                }
            }
        });

        $this->home()->assertOk();

        $this->assertNotEmpty($origins, 'Precondition: the conversation figure was read.');
        $this->assertSame(['app/Library/Conversations/BusinessConversationReadModel.php'], array_values(array_unique($origins)));

        // And B5's own code still never names that table (its docblock says so
        // in prose; what matters is that no statement does).
        foreach (['BusinessAnalyticsQueries', 'BusinessDashboardAnalyticsPresenter'] as $class) {
            $this->assertStringNotContainsString(
                'chat_boxes',
                $this->codeWithoutComments(app_path('Library/Analytics/' . $class . '.php')),
                "{$class} must leave conversations to Slice 2B.",
            );
        }
    }

    public function test_a_rise_is_described_and_never_presented_as_a_win(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Rise Venue', 'Rise Account');
        $this->contactsAdded($business, 9, '2026-09-02');
        $this->contactsAdded($business, 1, '2026-08-25');
        $this->conversationsStarted($business, 9, '2026-09-02');
        $this->conversationsStarted($business, 1, '2026-08-25');
        $this->receivedAt($business, 9, $this->localNoon('2026-09-02', $business->timezone));
        $this->receivedAt($business, 1, $this->localNoon('2026-08-25', $business->timezone));
        $this->authenticateAs($customer);

        $band = $this->performance($customer->user);
        $html = $this->home()->assertOk()->getContent();

        foreach ($band['items'] as $headline) {
            $this->assertNull($headline->judgement, "{$headline->key} carries no judgement.");
            $this->assertDoesNotMatchRegularExpression('/\b(good|great|success(ful)?|healthy|better|improved|win)\b/i', $headline->interpretation, $headline->key);
            $this->assertStringContainsString('the previous 10 days', $headline->comparisonSentence, 'The comparison names the real length of the window it used.');
        }

        $this->assertStringNotContainsString('data-role="headline-judgement"', $html);
        $this->assertStringContainsString('Up 8 (800.0%) from 1 in the previous 10 days.', $this->mainText($html));
    }

    public function test_the_period_caption_states_both_windows(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Caption Venue', 'Caption Account');
        $this->authenticateAs($customer);

        $band = $this->bandHtml($this->get(route('user.home', ['range' => AnalyticsDateRange::PRESET_LAST_MONTH]))->assertOk()->getContent(), 'headlines');

        $this->assertStringContainsString('Aug 1 – Aug 31, compared with the 31 days before it (Jul 1 – Jul 31).', html_entity_decode($band));
    }

    // =================================================================
    // Cache identity
    // =================================================================

    public function test_two_different_periods_never_share_a_cached_figure(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Cache Venue', 'Cache Account');
        // Only July has contacts: a window that excludes July must never be
        // served July's figure, and the reverse.
        $this->contactsAdded($business, 8, '2026-07-10');
        $this->authenticateAs($customer);
        $july = ['range' => 'custom', 'start' => '2026-07-01', 'end' => '2026-07-31'];

        Cache::flush();
        $first = $this->performance($customer->user, $july, flush: false);
        $thisMonth = $this->performance($customer->user, [], flush: false);
        $again = $this->performance($customer->user, $july, flush: false);

        $contacts = fn (array $band) => $this->byKey($band)['new_contacts']->comparison->current;

        $this->assertSame(8, $contacts($first));
        $this->assertSame(0, $contacts($thisMonth), 'A different window is a different cache entry.');
        $this->assertSame(8, $contacts($again), 'And the first window is still itself afterwards.');

        $this->assertNotSame(
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $first['range']),
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $thisMonth['range']),
        );
        $this->assertNotSame(
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $first['range']),
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $first['previousRange']),
            'The two periods of one view never share a key either.',
        );
    }

    public function test_two_equally_long_custom_windows_have_different_cache_keys(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Key Venue', 'Key Account');

        $first = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-07-01', 'end' => '2026-07-10'], self::TZ);
        $second = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-10'], self::TZ);

        $this->assertSame($first->days(), $second->days());
        $this->assertNotSame(
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $first),
            BusinessDashboardAnalyticsPresenter::cacheKey($business, $second),
            'The key carries the window itself, not merely its length.',
        );
    }

    // =================================================================
    // The chart
    // =================================================================

    public function test_the_initial_home_request_loads_no_series_and_hands_the_chart_the_selected_range(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Chart Venue', 'Chart Account');
        $this->contactsAdded($business, 4, '2026-08-03');
        $this->authenticateAs($customer);

        $recorder = $this->recordSeriesCalls();
        $html = $this->get(route('user.home', ['range' => AnalyticsDateRange::PRESET_LAST_MONTH]))->assertOk()->getContent();
        $band = $this->bandHtml($html, 'headlines');

        $this->assertSame([], $recorder->seriesCalls, 'The Home request itself computes no series.');

        $expected = route('customer.workspaces.businesses.analytics.series', [
            $workspace->uid, $business->uid, 'range' => AnalyticsDateRange::PRESET_LAST_MONTH,
        ]);

        $this->assertStringContainsString('data-role="chart-new-contacts"', $band);
        $this->assertStringContainsString('data-series-url="' . e($expected) . '"', $band, 'The chart asks for the very period the tiles show.');
        $this->assertStringContainsString('data-role="chart-placeholder"', $band, 'The page ships without a series.');

        // And that endpoint — B5's own, not a second one — answers for it.
        $payload = $this->getJson($expected)->assertOk()->json();
        $this->assertArrayHasKey('new_contacts', $payload['charts']);
        $this->assertSame('2026-08-01', $payload['range']['start_local']);
        $this->assertSame('2026-08-31', $payload['range']['end_local']);
        $this->assertNotEmpty($recorder->seriesCalls, 'The series endpoint is where a series is actually computed.');
    }

    public function test_a_custom_range_reaches_both_the_chart_and_the_results_link(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Custom Chart Venue', 'Custom Chart Account');
        $this->authenticateAs($customer);

        $query = ['range' => 'custom', 'start' => '2026-07-02', 'end' => '2026-07-20'];
        $band = $this->bandHtml($this->get(route('user.home', $query))->assertOk()->getContent(), 'headlines');

        foreach (['analytics.series', 'analytics.overview'] as $route) {
            $url = route('customer.workspaces.businesses.' . $route, array_merge([$workspace->uid, $business->uid], $query));
            $this->assertStringContainsString(e($url), $band, "{$route} carries the selected range.");
        }
    }

    public function test_see_details_opens_results_and_results_is_never_redirected_away(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Details Venue', 'Details Account');
        $this->authenticateAs($customer);

        $band = $this->bandHtml($this->home()->assertOk()->getContent(), 'headlines');
        $this->assertStringContainsString('data-role="results-link"', $band);
        $this->assertStringContainsString('See details', $band);

        // Results itself still answers on its own route: H-3 neither redirects
        // nor removes it.
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk();
    }

    // =================================================================
    // Permission
    // =================================================================

    public function test_business_performance_needs_view_reports(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Permission Venue', 'Permission Account');
        $this->contactsAdded($business, 7, '2026-09-02');
        $this->receivedAt($business, 4, $this->localNoon('2026-09-02', $business->timezone));
        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['view_reports'])));

        $snapshot = $this->dashboardFor($customer->user);
        $html = $this->home()->assertOk()->getContent();

        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_HEADLINES), 'No permission, no band.');
        $this->assertNotContains(DashboardSnapshot::BAND_HEADLINES, $snapshot->failedBands, 'Absent because it is not permitted, not because it broke.');
        $this->assertSame([], $this->headlineKeys($html));
        $this->assertStringNotContainsString('Business performance', $this->mainText($html));
        $this->assertStringNotContainsString('data-series-url', $html, 'And no series endpoint is handed out.');
        $this->assertStringNotContainsString('data-role="analytics-range"', $html);
    }

    // =================================================================
    // H-1 and H-2 are untouched by the period
    // =================================================================

    public function test_the_activity_band_and_visit_marker_are_unaffected_by_the_selected_period(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Preserved Venue', 'Preserved Account');
        $this->authenticateAs($customer);
        $storage = (string) config('app.timezone', 'UTC');
        $stamp = fn (CarbonImmutable $instant) => $instant->setTimezone($storage)->format('Y-m-d H:i:s');
        $this->previousHomeVisit($business, (int) $customer->user_id, $stamp(CarbonImmutable::now()->subHours(3)));
        $this->contactsAt($business, 2, $stamp(CarbonImmutable::now()->subHour()));

        $default = $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_ACTIVITY);
        $html = $this->get(route('user.home', ['range' => AnalyticsDateRange::PRESET_LAST_90_DAYS]))->assertOk()->getContent();
        $ninety = $this->dashboardFor($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_90_DAYS])->band(DashboardSnapshot::BAND_ACTIVITY);

        $this->assertSame('Today so far', $default['window']->label());
        $this->assertSame('Today so far', $ninety['window']->label(), 'The activity window is the visit marker, never the performance period.');
        $this->assertSame($default['items'], $ninety['items']);
        $this->assertSame([['key' => 'new_contacts', 'text' => '2 new contacts']], $ninety['items']);
        $this->assertStringContainsString('Business activity', $this->mainText($html));
        $this->assertSame(1, DB::table('business_home_visits')->count(), 'One marker row, per user and Business.');
    }

    public function test_a_first_visit_still_synthesizes_no_activity_whatever_period_is_selected(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'First Venue', 'First Account');
        $this->authenticateAs($customer);

        $snapshot = $this->dashboardFor($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_30_DAYS]);

        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_ACTIVITY), 'A first visit has nothing to compare against.');
        $this->assertTrue($snapshot->has(DashboardSnapshot::BAND_HEADLINES), 'Business performance does not depend on a previous visit.');
    }

    public function test_no_routine_spend_figure_returns_with_the_period_selector(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Spend Venue', 'No Spend Account');
        $this->wallet($business, [
            'available_balance_micro' => 25000000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 1000000,
        ]);
        $this->authenticateAs($customer);

        $main = $this->mainText($this->get(route('user.home', ['range' => AnalyticsDateRange::PRESET_LAST_30_DAYS]))->assertOk()->getContent());

        $this->assertDoesNotMatchRegularExpression('/\b(Spend and billing|Available balance|Add funds|wallet)\b/i', $main);
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', $main);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function performance(User $user, array $query = [], bool $flush = true): array
    {
        if ($flush) {
            Cache::flush();
        }

        $snapshot = $this->dashboardFor($user, $query);
        $this->assertSame(DashboardSnapshot::KIND_BUSINESS, $snapshot->kind);
        $band = $snapshot->band(DashboardSnapshot::BAND_HEADLINES);
        $this->assertIsArray($band, 'The Business performance band is present.');

        return $band;
    }

    /**
     * @param  array<string, mixed>  $band
     * @return array<string, \App\Library\Dashboard\Headline>
     */
    private function byKey(array $band): array
    {
        $figures = [];

        foreach ($band['items'] as $headline) {
            $figures[$headline->key] = $headline;
        }

        return $figures;
    }

    /**
     * B5's own query object, recording every series call made through it —
     * the one mechanical way to prove which request computes a series.
     */
    private function recordSeriesCalls(): BusinessAnalyticsQueries
    {
        $recorder = new class extends BusinessAnalyticsQueries
        {
            /** @var array<int, string> */
            public array $seriesCalls = [];

            public function contactGrowthSeries(Business $business, AnalyticsDateRange $range): DailySeries
            {
                $this->seriesCalls[] = 'contactGrowthSeries';

                return parent::contactGrowthSeries($business, $range);
            }

            public function messageVolumeSeries(Business $business, AnalyticsDateRange $range): DailySeries
            {
                $this->seriesCalls[] = 'messageVolumeSeries';

                return parent::messageVolumeSeries($business, $range);
            }
        };

        $this->app->instance(BusinessAnalyticsQueries::class, $recorder);

        return $recorder;
    }

    /** A PHP file with every comment removed, so prose cannot satisfy or break a source rule. */
    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array<int, string> */
    private function headlineKeys(string $html): array
    {
        preg_match_all('/data-headline="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function bandHtml(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);

        return substr($main, $start, $end === false ? null : $end - $start);
    }
}
