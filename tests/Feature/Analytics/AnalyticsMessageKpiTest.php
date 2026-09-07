<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §5 (M1–M7), §3.1/§3.2 (NULL-business exclusion + coverage
 * notice), §21 "M4 regression", "M5 / M6", "Identity", "NULL-business
 * exclusion".
 */
class AnalyticsMessageKpiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private function kpis($business, string $preset = AnalyticsDateRange::PRESET_LAST_30_DAYS)
    {
        $range = AnalyticsDateRange::preset($preset, $business->timezone);

        return app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range);
    }

    public function test_m4_counts_exact_delivered_and_delivered_pipe_suffix_but_never_undelivered(): void
    {
        [, $business] = $this->tenant();
        $u = $business->customer_id;

        $this->report($business, $u, ['customer_status' => 'Delivered']);
        $this->report($business, $u, ['customer_status' => 'Delivered|SM12345']);
        $this->report($business, $u, ['customer_status' => 'Undelivered']);

        $m = $this->kpis($business)->messages;

        $this->assertSame(3, $m->outbound);
        $this->assertSame(2, $m->accepted, 'Undelivered must never be counted as accepted.');
        $this->assertSame(1, $m->confirmedFailed);
        $this->assertSame(0, $m->unresolved());
    }

    public function test_m5_vocabulary_and_m6_home_for_everything_else(): void
    {
        [, $business] = $this->tenant();
        $u = $business->customer_id;

        foreach (['Undelivered', 'Expired', 'Rejected', 'Failed', 'Skipped'] as $terminal) {
            $this->report($business, $u, ['customer_status' => $terminal]);
        }

        foreach (['Enroute', 'Accepted', 'queued', 'Twilio: Error 30007 - Carrier violation', null] as $unresolved) {
            $this->report($business, $u, ['customer_status' => $unresolved]);
        }

        $m = $this->kpis($business)->messages;

        $this->assertSame(10, $m->outbound);
        $this->assertSame(0, $m->accepted);
        $this->assertSame(5, $m->confirmedFailed);
        $this->assertSame(5, $m->unresolved());
    }

    public function test_identity_m4_plus_m5_plus_m6_equals_m1_over_every_status_class(): void
    {
        [, $business] = $this->tenant();
        $u = $business->customer_id;

        foreach (['Delivered', 'Delivered|abc', 'Undelivered', 'Expired', 'Rejected', 'Failed', 'Skipped', 'Enroute', 'Accepted', 'weird provider text', null] as $status) {
            $this->report($business, $u, ['customer_status' => $status]);
        }

        $m = $this->kpis($business)->messages;

        $this->assertSame(11, $m->outbound);
        $this->assertSame($m->outbound, $m->accepted + $m->confirmedFailed + $m->unresolved());
        $this->assertSame(2, $m->accepted);
        $this->assertSame(5, $m->confirmedFailed);
        $this->assertSame(4, $m->unresolved());
    }

    public function test_api_and_inbound_are_separate_and_never_merged_into_outbound(): void
    {
        [, $business] = $this->tenant();
        $u = $business->customer_id;

        $this->report($business, $u, ['direction' => 'outgoing']);
        $this->report($business, $u, ['direction' => 'api']);
        $this->report($business, $u, ['direction' => 'api']);
        $this->report($business, $u, ['direction' => 'incoming']);
        $this->report($business, $u, ['direction' => 'incoming']);
        $this->report($business, $u, ['direction' => 'incoming']);

        $m = $this->kpis($business)->messages;

        $this->assertSame(1, $m->outbound);
        $this->assertSame(2, $m->api);
        $this->assertSame(3, $m->inbound);
        $this->assertSame(1, $m->accepted, 'API and inbound rows never enter the M4 numerator.');
    }

    public function test_null_business_rows_of_the_same_customer_are_excluded_and_reported_in_the_coverage_notice(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $u = $business->customer_id;

        $this->report($business, $u);
        $this->report(null, $u);
        $this->report(null, $u, ['direction' => 'incoming']);

        $overview = $this->kpis($business);

        $this->assertSame(1, $overview->messages->outbound);
        $this->assertSame(0, $overview->messages->inbound);
        $this->assertSame(2, $overview->coverage->unattributedMessages);
        $this->assertTrue($overview->coverage->shouldRender());

        $this->authenticateAsCustomer($customer);
        $this->overview($workspace, $business)
            ->assertOk()
            ->assertSee('data-role="coverage-notice"', false)
            ->assertSee('2 message records')
            ->assertSee('could not be attributed to a Business and are not included in these figures');
    }

    public function test_coverage_notice_is_absent_when_every_row_is_attributed(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->report($business, $business->customer_id);
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()->assertDontSee('data-role="coverage-notice"', false);
    }

    public function test_another_business_rows_never_appear_even_under_the_same_customer(): void
    {
        [$customer, $businessA, $workspace] = $this->tenant();
        $businessB = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Other Venue']));
        $u = $businessA->customer_id;

        $this->report($businessA, $u);
        $this->report($businessB, $u);
        $this->report($businessB, $u);

        $this->assertSame(1, $this->kpis($businessA)->messages->outbound);
        $this->assertSame(2, $this->kpis($businessB)->messages->outbound);
    }

    public function test_m7_daily_series_is_split_by_direction_on_business_local_dates(): void
    {
        [, $business] = $this->tenant('America/New_York');
        $u = $business->customer_id;
        $tz = 'America/New_York';

        // Local 2026-06-10 23:30 (EDT) is 2026-06-11 03:30 UTC: still day 10 locally.
        $this->report($business, $u, ['created_at' => $this->utcFromLocal('2026-06-10 23:30:00', $tz)]);
        $this->report($business, $u, ['direction' => 'incoming', 'created_at' => $this->utcFromLocal('2026-06-10 08:00:00', $tz)]);
        $this->report($business, $u, ['direction' => 'api', 'created_at' => $this->utcFromLocal('2026-06-11 00:10:00', $tz)]);

        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-11'], $tz);
        $series = app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range)->messageVolume;

        $this->assertSame(['2026-06-10', '2026-06-11'], $series->dates);
        $this->assertSame([1, 0], $series->series['outgoing']);
        $this->assertSame([1, 0], $series->series['incoming']);
        $this->assertSame([0, 1], $series->series['api']);
    }

    public function test_rates_are_null_safe_and_never_exceed_100_percent(): void
    {
        [, $business] = $this->tenant();

        $empty = $this->kpis($business)->messages;
        $this->assertNull($empty->acceptedRate());
        $this->assertSame(0, $empty->unresolved());

        $this->report($business, $business->customer_id, ['customer_status' => 'Delivered']);
        $full = $this->kpis($business, AnalyticsDateRange::PRESET_LAST_7_DAYS)->messages;
        $this->assertSame(100.0, $full->acceptedRate());
    }
}
