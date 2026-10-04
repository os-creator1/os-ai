<?php

namespace Tests\Feature\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\GoogleAdsMoney;
use App\Library\GoogleAds\Reporting\GoogleAdsBudgetReader;
use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsOverviewReader;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsTrendSeries;
use App\Models\GoogleAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\TestCase;

/**
 * Contract §9 — Overview KPIs, trend series, budget/pacing reads. Clock pinned
 * to 2026-10-04 12:00 UTC (08:00 on Oct 4 in the New York account zone).
 */
class GoogleAdsOverviewReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGoogleAdsReportingData;

    private GoogleAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinAdsClock();
        [, $business] = $this->adsTenant();
        $this->account = $this->adsAccountFor($business);
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    private function overview(string $period = 'last_30')
    {
        return app(GoogleAdsOverviewReader::class)->read($this->account, GoogleAdsPeriod::resolve($period, $this->account));
    }

    public function test_no_rows_means_nulls_never_fabricated_zeros(): void
    {
        $o = $this->overview();

        $this->assertFalse($o->hasData);
        $this->assertNull($o->spendMicros);
        $this->assertNull($o->googleConversions);
        $this->assertNull($o->cplMicros);
        $this->assertNull($o->conversionRate);
        $this->assertNull($o->conversionValue);
        $this->assertNull($o->clicks);
        $this->assertNull($o->impressions);
        $this->assertNull($o->projectedMonthEndSpendMicros);
        $this->assertNull($o->comparison);
        $this->assertFalse($o->coverage['covered']);
        $this->assertSame('—', GoogleAdsMoney::format($o->spendMicros, $o->currencyCode));
    }

    public function test_kpis_sum_campaign_rows_and_never_double_count_keyword_rows(): void
    {
        $campaign = $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-09-20', '2026-10-03', 10_000_000, 10, '1', '50');
        // Keyword rows for the same campaign: must NOT change account KPIs.
        foreach (['5~11', '5~12'] as $key) {
            $this->seedCampaignKeywordDays($key, '2026-09-20', '2026-10-03');
        }

        $o = $this->overview();

        $this->assertTrue($o->hasData);
        $this->assertSame(140_000_000, $o->spendMicros);
        $this->assertSame('14.000000', $o->googleConversions);
        $this->assertSame(10_000_000, $o->cplMicros);
        $this->assertEqualsWithDelta(0.1, $o->conversionRate, 1e-9);
        $this->assertSame('700.000000', $o->conversionValue);
        $this->assertSame(140, $o->clicks);
        $this->assertSame(1400, $o->impressions);
        $this->assertSame('USD 140.00', GoogleAdsMoney::format($o->spendMicros, $o->currencyCode));
        $this->assertSame('USD 10.00', GoogleAdsMoney::format($o->cplMicros, $o->currencyCode));
        $this->assertNotNull($campaign->uid);
    }

    private function seedCampaignKeywordDays(string $key, string $from, string $to): void
    {
        for ($d = \Carbon\CarbonImmutable::parse($from); $d->lessThanOrEqualTo(\Carbon\CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $this->seedMetric($this->account, GoogleAdsMetricLevel::Keyword, $key, $d->format('Y-m-d'), 99_000_000, 99, '9');
        }
    }

    public function test_rows_with_zero_conversions_give_zero_conversions_but_null_cpl_and_zero_rate(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 5_000_000, 4, '0');

        $o = $this->overview();

        $this->assertSame('0.000000', $o->googleConversions);
        $this->assertNull($o->cplMicros);
        $this->assertSame(0.0, $o->conversionRate);
        $this->assertNull($o->conversionValue, 'no value rows > 0 => null, not 0');
        $this->assertSame(15_000_000, $o->spendMicros);
    }

    public function test_null_conversions_in_rows_means_no_conversion_data(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 5_000_000, 4, null);

        $o = $this->overview();

        $this->assertSame(15_000_000, $o->spendMicros);
        $this->assertNull($o->googleConversions);
        $this->assertNull($o->cplMicros);
        $this->assertNull($o->conversionRate);
    }

    public function test_conversion_rate_is_null_when_there_were_no_clicks(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-02', 1_000_000, 0, '0');

        $this->assertNull($this->overview()->conversionRate);
    }

    public function test_projected_month_end_spend_and_low_confidence(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 10_000_000);

        $o = $this->overview('last_7');

        // 30 spent over 3 days with data, October has 31 days: 30 / 3 x 31 = 310.
        $this->assertSame(310_000_000, $o->projectedMonthEndSpendMicros);
        $this->assertTrue($o->projectionLowConfidence);
        $this->assertSame(3, $o->pacing->daysWithData);
    }

    public function test_projection_is_confident_with_enough_days_and_ignores_the_selected_period(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-09-01', '2026-09-30', 1_000_000);
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 10_000_000);

        $o = $this->overview('previous_month');

        $this->assertSame(30_000_000, $o->spendMicros, 'the period is September');
        $this->assertSame(310_000_000, $o->projectedMonthEndSpendMicros, 'projection is October month-to-date');
    }

    public function test_comparison_block_and_change_ratios(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        // last_7 = Sep 28..Oct 4; comparison = Sep 21..Sep 27.
        $this->seedCampaignDays($this->account, '1000000001', '2026-09-28', '2026-10-03', 10_000_000, 10, '2');
        $this->seedCampaignDays($this->account, '1000000001', '2026-09-21', '2026-09-27', 4_000_000, 10, '1');

        $o = $this->overview('last_7');

        $this->assertSame(60_000_000, $o->spendMicros);
        $this->assertSame(28_000_000, $o->comparison['spend_micros']);
        $this->assertEqualsWithDelta(60 / 28 - 1, $o->comparison['spend_change'], 1e-3);
        $this->assertSame('2026-09-21', $o->comparison['period']['from']);
    }

    public function test_freshness_inputs_come_from_the_account_row(): void
    {
        $this->account->forceFill([
            'last_successful_sync_at' => '2026-10-04 06:00:00',
            'data_through_date' => '2026-10-03',
            'last_sync_failure_code' => 'row_cap',
        ])->save();

        $o = $this->overview();

        $this->assertSame('2026-10-04 06:00:00', $o->lastSuccessfulSyncAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03', $o->dataThroughDate->format('Y-m-d'));
        $this->assertSame('row_cap', $o->lastSyncFailureCode);
    }

    public function test_coverage_requires_cached_days_inside_the_sync_window(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-09-01', '2026-10-03', 1_000_000);
        $this->account->forceFill(['data_through_date' => '2026-10-03'])->save();
        $reader = app(GoogleAdsOverviewReader::class);

        foreach (['last_7', 'last_30', 'this_month', 'previous_month'] as $key) {
            $this->assertTrue($reader->coverage($this->account, GoogleAdsPeriod::resolve($key, $this->account))['covered'], $key);
        }

        // A period that starts before the 62-day sync window is not covered.
        $old = GoogleAdsPeriod::resolveIn('last_30', 'America/New_York', \Carbon\CarbonImmutable::parse('2026-12-20 12:00:00'));
        $this->assertFalse($reader->coverage($this->account, $old)['covered']);
    }

    public function test_the_overview_never_calls_a_provider_client(): void
    {
        // Every provider contract throws when resolved; a reader that touched one would fail.
        foreach ([\App\Library\GoogleAds\Contracts\GoogleAdsReadClient::class, \App\Library\GoogleAds\Contracts\GoogleAdsMutationClient::class, \App\Library\GoogleAds\Contracts\GoogleAdsAuthClient::class] as $contract) {
            $this->app->bind($contract, static fn () => throw new \RuntimeException('provider client resolved by a reader'));
        }
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 1_000_000);

        $this->assertSame(3_000_000, $this->overview()->spendMicros);
    }

    public function test_period_falls_back_to_business_timezone_then_utc(): void
    {
        $business = \App\Models\Business::query()->findOrFail($this->account->business_id);
        $business->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $this->account->forceFill(['time_zone' => 'Not/AZone'])->save();

        $this->assertSame('Pacific/Auckland', GoogleAdsPeriod::resolve('last_7', $this->account->fresh())->timezone);

        $business->forceFill(['timezone' => 'bogus'])->save();
        $this->assertSame('UTC', GoogleAdsPeriod::resolve('last_7', $this->account->fresh())->timezone);

        $this->assertSame('America/New_York', GoogleAdsPeriod::resolve('last_7', $this->account->fresh()->forceFill(['time_zone' => 'America/New_York']))->timezone);
    }

    public function test_trend_series_shape_zero_fill_and_null_conversions(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'Rental');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000001', '2026-10-01', 8_000_000, 5, '2');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000001', '2026-10-02', 6_000_000, 3, '0');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000001', '2026-10-03', 5_000_000, 3, null);

        $t = app(GoogleAdsTrendSeries::class)->forPeriod($this->account, GoogleAdsPeriod::resolve('last_7', $this->account));

        $this->assertCount(7, $t['labels']);
        $this->assertCount(7, $t['tooltips']);
        $this->assertSame('Mon 28', $t['labels'][0]);
        $this->assertSame('Mon, Sep 28, 2026', $t['tooltips'][0]);
        foreach ($t['series'] as $name => $values) {
            $this->assertCount(7, $values, $name);
        }
        $this->assertTrue($t['has_data']);
        $this->assertTrue($t['has_conversion_data']);
        $this->assertSame('USD', $t['currency']);

        // Days Sep 28..30 have no rows: spend / clicks zero-filled, conversions and CPL null.
        $this->assertSame([0.0, 0.0, 0.0], array_slice($t['series']['spend'], 0, 3));
        $this->assertSame([0, 0, 0], array_slice($t['series']['clicks'], 0, 3));
        $this->assertSame([null, null, null], array_slice($t['series']['conversions'], 0, 3));
        $this->assertSame([null, null, null], array_slice($t['series']['cpl'], 0, 3));

        // Oct 1 converts, Oct 2 is a true zero (CPL null), Oct 3 has no conversion data, Oct 4 has no row.
        $this->assertSame(8.0, $t['series']['spend'][3]);
        $this->assertSame(8_000_000, $t['series']['spend_micros'][3]);
        $this->assertSame(2.0, $t['series']['conversions'][3]);
        $this->assertSame(4.0, $t['series']['cpl'][3]);
        $this->assertSame(0.0, $t['series']['conversions'][4]);
        $this->assertNull($t['series']['cpl'][4]);
        $this->assertNull($t['series']['conversions'][5]);
        $this->assertNull($t['series']['cpl'][5]);
        $this->assertSame(0.0, $t['series']['spend'][6]);
        $this->assertNull($t['series']['conversions'][6]);
    }

    public function test_trend_series_for_a_period_with_no_data_is_well_formed_and_flags_it(): void
    {
        $t = app(GoogleAdsTrendSeries::class)->forPeriod($this->account, GoogleAdsPeriod::resolve('last_30', $this->account));

        $this->assertFalse($t['has_data']);
        $this->assertFalse($t['has_conversion_data']);
        $this->assertCount(30, $t['labels']);
        $this->assertSame('Sep 5', $t['labels'][0]);
    }

    public function test_trend_series_campaign_filter_resolves_inside_the_account(): void
    {
        $a = $this->seedCampaign($this->account, '1000000001', 'A');
        $this->seedCampaign($this->account, '1000000002', 'B');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000001', '2026-10-03', 3_000_000);
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000002', '2026-10-03', 9_000_000);
        $period = GoogleAdsPeriod::resolve('last_7', $this->account);
        $trend = app(GoogleAdsTrendSeries::class);

        $this->assertSame(3_000_000, $trend->forPeriod($this->account, $period, $a->uid)['series']['spend_micros'][5]);
        $this->assertSame(12_000_000, $trend->forPeriod($this->account, $period)['series']['spend_micros'][5]);
        $this->assertFalse($trend->forPeriod($this->account, $period, 'not-a-uid')['has_data']);
    }

    public function test_budget_reader_pacing_status_and_cpl_status(): void
    {
        $this->pinAdsClock('2026-10-12 12:00:00');
        $this->account->forceFill(['monthly_budget_target_micros' => 310_000_000, 'target_cpl_micros' => 10_000_000])->save();
        $this->seedCampaign($this->account, '1000000001', 'A', ['budget_amount_micros' => 8_000_000]);
        // Oct 1..11 = 11 days, 20 spent per day => 220 vs expected 310 x 11/31 = 110 => ahead.
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-11', 20_000_000, 10, '1');

        $budget = app(GoogleAdsBudgetReader::class)->read($this->account->fresh(), GoogleAdsPeriod::resolve('this_month', $this->account));

        $this->assertSame(GoogleAdsPacingStatus::Ahead, $budget->pacing->status);
        $this->assertSame(220_000_000, $budget->pacing->spentMicros);
        $this->assertSame(310_000_000, $budget->pacing->monthlyTargetMicros);
        $this->assertSame(11, $budget->pacing->daysElapsed);
        $this->assertSame(20_000_000, $budget->currentCplMicros);
        $this->assertSame(GoogleAdsCplStatus::Worse, $budget->cplStatus);

        // Campaign DAILY budget stays a separate field from the monthly target.
        $row = $budget->campaigns[0];
        $this->assertSame(8_000_000, $row->dailyBudgetMicros);
        $this->assertSame(220_000_000, $row->spendMicros());
        $this->assertSame(310_000_000, $budget->pacing->monthlyTargetMicros);
    }

    public function test_budget_reader_without_targets_or_conversions(): void
    {
        $this->seedCampaign($this->account, '1000000001', 'A');
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 5_000_000, 10, null);

        $budget = app(GoogleAdsBudgetReader::class)->read($this->account);

        $this->assertSame(GoogleAdsPacingStatus::NoTarget, $budget->pacing->status);
        $this->assertNull($budget->targetCplMicros);
        $this->assertNull($budget->currentCplMicros);
        $this->assertSame(GoogleAdsCplStatus::NoTarget, $budget->cplStatus);

        $this->account->forceFill(['target_cpl_micros' => 10_000_000])->save();
        $this->assertNull(app(GoogleAdsBudgetReader::class)->read($this->account->fresh())->cplStatus, 'target but no current CPL => no status');
    }
}
