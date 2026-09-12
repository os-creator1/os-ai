<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §11 (bounded query count, Business-scoped cache, throttle)
 * and §21 "Cache isolation", "Query count / no N+1", "Throttle".
 */
class AnalyticsPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private const SOURCE_TABLES = '/\b(reports|tracking_logs|campaigns|contacts|contact_groups|opportunities|opportunity_runs|automation_executions)\b/';

    public function test_overview_issues_at_most_twelve_queries_including_the_tenancy_chain(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        config(['opportunity.enabled' => true]);
        $group = $this->group($business);
        $this->contact($business, $group);
        $this->report($business, $business->customer_id);
        $this->campaign($business);
        $this->authenticateAsCustomer($customer);

        $sql = $this->analyticsOwnedSql(fn () => $this->overview($workspace, $business)->assertOk());

        $kpiQueries = array_values(array_filter($sql, fn (string $s) => preg_match(self::SOURCE_TABLES, $s) === 1));
        $tenancyQueries = array_values(array_filter($sql, fn (string $s) => preg_match('/\b(workspaces|businesses|workspace_memberships|workspace_membership_businesses)\b/', $s) === 1));

        // Contract §11.2: the analytics-owned cost — the §2.2 tenancy chain
        // plus the batched KPI reads — is at most 12. The shared page chrome
        // (plan card, notifications, theme preset, languages, and Slice 2A's
        // menu-entitlement snapshot, excluded by caller in
        // analyticsOwnedSql()) is rendered by the layout for every customer
        // page and is outside this budget.
        $this->assertLessThanOrEqual(7, count($kpiQueries), 'KPI reads must be batched (M, M7, C, K, K3, O, A): ' . implode(' | ', $kpiQueries));
        $this->assertLessThanOrEqual(12, count($kpiQueries) + count($tenancyQueries), 'Overview budget: ' . (count($kpiQueries) + count($tenancyQueries)) . ' tenancy+KPI queries');

        // No query per campaign, contact or day: every KPI statement is an
        // aggregate.
        foreach ($kpiQueries as $statement) {
            $this->assertMatchesRegularExpression('/\b(count|sum)\(/i', $statement, 'Non-aggregate analytics query: ' . $statement);
        }
    }

    public function test_a_cached_overview_issues_no_kpi_query_at_all(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);
        $this->overview($workspace, $business)->assertOk();

        $sql = $this->capturedSql(fn () => $this->overview($workspace, $business)->assertOk());

        $this->assertSame([], array_values(array_filter($sql, fn (string $s) => preg_match(self::SOURCE_TABLES, $s) === 1)));
    }

    public function test_cache_is_business_scoped_and_carries_the_range(): void
    {
        [$customer, $businessA, $workspace] = $this->tenant();
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Other Venue']));
        $u = $businessA->customer_id;
        $this->report($businessA, $u);
        $this->report($businessB, $u);
        $this->report($businessB, $u);
        $presenter = app(BusinessAnalyticsPresenter::class);
        $range30 = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $businessA->timezone);
        $range7 = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_7_DAYS, $businessA->timezone);

        $this->assertSame(1, $presenter->buildOverview($businessA, $range30)->messages->outbound);
        $this->assertSame(2, $presenter->buildOverview($businessB, $range30)->messages->outbound, 'Business B must never be served Business A\'s payload.');

        $this->assertTrue(Cache::has('b5_analytics_' . $businessA->id . '_last_30_days'));
        $this->assertTrue(Cache::has('b5_analytics_' . $businessB->id . '_last_30_days'));
        $this->assertFalse(Cache::has('b5_analytics_' . $businessA->id . '_last_7_days'));
        $this->assertFalse(Cache::has('b5_analytics_last_30_days'), 'No global analytics key may exist.');
        $this->assertFalse(Cache::has('b5_analytics'));

        $presenter->buildOverview($businessA, $range7);
        $this->assertTrue(Cache::has('b5_analytics_' . $businessA->id . '_last_7_days'));

        // A new row after caching is invisible until the 5-minute TTL — by design.
        $this->report($businessA, $u);
        $this->assertSame(1, $presenter->buildOverview($businessA, $range30)->messages->outbound);
        $this->assertSame(300, BusinessAnalyticsPresenter::CACHE_TTL_SECONDS);
    }

    /**
     * Contract §11.4 — `throttle:60,1` on /series. The middleware refuses
     * the 61st request within a minute (ThrottleRequestsException, HTTP
     * 429). This application's global exception handler
     * (app/Exceptions/Handler.php, outside the B5 allowlist) then rewrites
     * every exception on a JSON request into a 200 "error" envelope and
     * every HttpException on an HTML request into the 404 page, so the
     * status code that reaches the client is the handler's, not the
     * middleware's. What B5 controls — and proves here — is that the
     * limit is attached and enforced: sixty requests succeed with a real
     * series payload and the sixty-first is refused with no payload.
     */
    public function test_series_is_refused_after_sixty_requests_in_one_minute(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        for ($i = 1; $i <= 60; $i++) {
            $this->series($workspace, $business)->assertOk()->assertJsonStructure(['contact_growth', 'message_volume']);
        }

        $refused = $this->series($workspace, $business);

        $this->assertNull($refused->json('contact_growth'), 'The 61st request must not deliver a series payload.');
        $this->assertTrue(
            $refused->getStatusCode() === 429 || $refused->json('status') === 'error',
            'Expected a throttled refusal, got HTTP ' . $refused->getStatusCode() . ': ' . $refused->getContent()
        );
    }
}
