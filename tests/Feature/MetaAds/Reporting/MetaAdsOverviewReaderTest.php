<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
use App\Library\MetaAds\MetaAdsMoney;
use App\Library\MetaAds\Reporting\MetaAdsBudgetReader;
use App\Library\MetaAds\Reporting\MetaAdsOverviewReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\MetaAds\Reporting\MetaAdsTrendSeries;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\TestCase;

/**
 * Contract 24 section 5 / 9 - Overview KPIs, trend series, budget/pacing,
 * period handling, money and missing-data semantics. Clock pinned to
 * 2026-10-04 12:00 UTC (08:00 on Oct 4 in the New York account zone).
 */
class MetaAdsOverviewReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAdsReportingData;

    private MetaAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinMetaClock();
        [, $business] = $this->metaReportingTenant();
        $this->account = $this->metaReportingAccountFor($business);
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    private function overview(string $period = 'last_30')
    {
        $account = $this->account->fresh();

        return app(MetaAdsOverviewReader::class)->read($account, MetaAdsPeriod::resolve($period, $account));
    }

    public function test_period_resolution_uses_the_account_time_zone_and_the_neutral_period_maths(): void
    {
        $p = MetaAdsPeriod::resolve('last_7', $this->account);
        $this->assertSame('2026-09-28', $p->fromDate());
        $this->assertSame('2026-10-04', $p->toDate());
        $this->assertSame('America/New_York', $p->timezone);

        // 2026-10-04 02:00 UTC is still Oct 3 in New York.
        $early = MetaAdsPeriod::resolve('this_month', $this->account, \Carbon\CarbonImmutable::parse('2026-10-04 02:00:00', 'UTC'));
        $this->assertSame('2026-10-03', $early->toDate());

        $this->assertSame('last_30', MetaAdsPeriod::resolve('nonsense', $this->account)->key);

        $this->account->update(['time_zone' => 'Not/AZone']);
        $fallback = MetaAdsPeriod::resolve(null, $this->account->fresh());
        $this->assertContains($fallback->timezone, [$this->account->business->timezone, 'UTC']);
    }

    public function test_no_rows_means_nulls_never_fabricated_zeros(): void
    {
        $o = $this->overview();

        $this->assertFalse($o->hasData);
        $this->assertNull($o->spendMicros);
        $this->assertNull($o->impressions);
        $this->assertNull($o->clicks);
        $this->assertNull($o->linkClicks);
        $this->assertNull($o->results);
        $this->assertNull($o->costPerResultMicros);
        $this->assertNull($o->ctr);
        $this->assertNull($o->resultValue);
        $this->assertNull($o->projectedMonthEndSpendMicros);
        $this->assertNull($o->comparison);
        $this->assertFalse($o->coverage['covered']);
        $this->assertTrue($o->resultTypeChosen);
        $this->assertSame('Leads (on-Facebook forms)', $o->resultTypeLabel);
        $this->assertSame('—', MetaAdsMoney::format($o->spendMicros, $o->currencyCode));
        $this->assertSame(GoogleAdsCplStatus::NoTarget, $o->targetStatus);
    }

    public function test_kpis_sum_campaign_rows_only(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $adSet = $this->seedMetaAdSet($campaign, 'Set');
        $ad = $this->seedMetaAd($adSet, 'Ad');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-09-20', '2026-10-03', 10_000_000, 1, 10, 1000, 20, '50');
        // Ad set and ad rows for the same spend: must NOT change account KPIs.
        $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $adSet->id, '2026-09-20', '2026-10-03', 10_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::Ad, $ad->id, '2026-09-20', '2026-10-03', 10_000_000, 1);

        $o = $this->overview();

        $this->assertTrue($o->hasData);
        $this->assertSame(140_000_000, $o->spendMicros);
        $this->assertSame(14_000, $o->impressions);
        $this->assertSame(280, $o->clicks);
        $this->assertSame(140, $o->linkClicks);
        $this->assertSame('14.000000', $o->results);
        $this->assertSame(10_000_000, $o->costPerResultMicros);
        $this->assertSame(0.01, $o->ctr);
        $this->assertSame('700.000000', $o->resultValue);
        $this->assertSame('onsite_conversion.lead_grouped', $o->resultType);
        $this->assertFalse($o->resultTypeUnset);
        $this->assertSame(14, $o->coverage['days_with_data']);
        $this->assertTrue($o->coverage['covered']);
        $this->assertSame('USD', $o->currencyCode);
        $this->assertSame($this->account->uid, $o->account['uid']);
        $this->assertSame('Snap Booth Ads', $o->account['name']);
        $this->assertArrayNotHasKey('ad_account_id', $o->account);
    }

    public function test_spend_without_a_chosen_result_type_makes_results_unavailable(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-09-20', '2026-10-03', 10_000_000, 2);
        $this->account->update(['result_action_type' => null]);

        $o = $this->overview();

        $this->assertSame(140_000_000, $o->spendMicros);
        $this->assertTrue($o->resultTypeUnset);
        $this->assertFalse($o->resultTypeChosen);
        $this->assertNull($o->resultType);
        $this->assertNull($o->resultTypeLabel);
        $this->assertNull($o->results);
        $this->assertNull($o->costPerResultMicros);
        $this->assertNull($o->resultValue);
        $this->assertSame(10_000_000 * 14, $o->spendMicros);
    }

    public function test_a_stored_type_outside_the_allow_list_is_unavailable(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 2, 10, 1000, 20, null);
        $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-02', 5, null, 'post_reaction');
        $this->account->update(['result_action_type' => 'post_reaction']);

        $this->assertNull($this->overview()->results);
    }

    public function test_switching_the_result_type_rereads_stored_rows_only_for_that_type(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 2);        // 6 leads
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $campaign->id, $day, 10, null, 'link_click');                 // 30 link clicks
        }

        $this->assertSame('6.000000', $this->overview()->results);
        $this->assertSame(5_000_000, $this->overview()->costPerResultMicros);

        $this->account->update(['result_action_type' => 'link_click']);
        $o = $this->overview();
        $this->assertSame('30.000000', $o->results);
        $this->assertSame(1_000_000, $o->costPerResultMicros);
        $this->assertSame('Link clicks', $o->resultTypeLabel);
    }

    public function test_chosen_type_with_no_stored_result_rows_is_unavailable_not_zero(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, null);

        $o = $this->overview();

        $this->assertSame(30_000_000, $o->spendMicros);
        $this->assertNull($o->results);
        $this->assertNull($o->costPerResultMicros);
    }

    public function test_null_link_clicks_are_not_zero_and_leave_ctr_null(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 1, null);

        $o = $this->overview();

        $this->assertNull($o->linkClicks);
        $this->assertNull($o->ctr);
        $this->assertSame(60, $o->clicks);
        $this->assertSame(3000, $o->impressions);
    }

    public function test_comparison_window_and_change_ratios(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-09-25', '2026-09-30', 15_000_000, 3);  // current (last_30: Sep 5..Oct 4)
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-08-20', '2026-08-25', 10_000_000, 3);  // previous (Aug 6..Sep 4)

        $o = $this->overview();

        $this->assertSame(90_000_000, $o->spendMicros);
        $this->assertNotNull($o->comparison);
        $this->assertSame('2026-08-06', $o->comparison['period']['from']);
        $this->assertSame('2026-09-04', $o->comparison['period']['to']);
        $this->assertSame(60_000_000, $o->comparison['spend_micros']);
        $this->assertSame(0.5, $o->comparison['spend_change']);
        $this->assertSame(0.0, $o->comparison['results_change']);
        $this->assertSame(0.5, $o->comparison['cost_per_result_change']);
    }

    public function test_period_change_only_refilters_cached_rows(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-09-10', '2026-09-12', 10_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-02', 20_000_000, 1);

        $this->assertSame(70_000_000, $this->overview('last_30')->spendMicros);
        $this->assertSame(40_000_000, $this->overview('this_month')->spendMicros);
        $this->assertSame(40_000_000, $this->overview('last_7')->spendMicros);
        $this->assertSame(30_000_000, $this->overview('previous_month')->spendMicros);
    }

    public function test_previous_month_period_counts_september_rows(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-09-10', '2026-09-12', 10_000_000, 1);

        $o = $this->overview('previous_month');

        $this->assertSame('2026-09-01', $o->period->fromDate());
        $this->assertSame('2026-09-30', $o->period->toDate());
        $this->assertSame(30_000_000, $o->spendMicros);
    }

    // ---- budget / pacing ------------------------------------------------------------------------

    public function test_projection_and_low_confidence_with_few_days(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 1);
        $this->account->update(['monthly_budget_target_micros' => 300_000_000]);

        $o = $this->overview();

        $this->assertSame(310_000_000, $o->projectedMonthEndSpendMicros);   // 30 / 3 x 31
        $this->assertTrue($o->projectionLowConfidence);
        $this->assertSame(GoogleAdsPacingStatus::InsufficientData, $o->pacing->status);
        $this->assertSame(300_000_000, $o->pacing->monthlyTargetMicros);
    }

    public function test_pacing_statuses_against_the_meta_monthly_target(): void
    {
        $this->pinMetaClock('2026-10-10 12:00:00');
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-09', 10_000_000, 1);
        $budget = app(MetaAdsBudgetReader::class);

        $cases = [200_000_000 => GoogleAdsPacingStatus::Ahead, 300_000_000 => GoogleAdsPacingStatus::OnPace, 400_000_000 => GoogleAdsPacingStatus::Behind];
        foreach ($cases as $target => $status) {
            $this->account->update(['monthly_budget_target_micros' => $target]);
            $this->assertSame($status, $budget->pacing($this->account->fresh())->status, (string) $target);
        }

        $this->account->update(['monthly_budget_target_micros' => null]);
        $this->assertSame(GoogleAdsPacingStatus::NoTarget, $budget->pacing($this->account->fresh())->status);
    }

    public function test_cost_per_result_target_status(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 1);   // 10.00 per result

        $expected = [
            12_000_000 => GoogleAdsCplStatus::Better,     // 10 < 12 x 0.85 = 10.2
            10_000_000 => GoogleAdsCplStatus::OnTarget,
        ];
        foreach ($expected as $target => $status) {
            $this->account->update(['target_cost_per_result_micros' => $target]);
            $o = $this->overview();
            $this->assertSame($status, $o->targetStatus, (string) $target);
            $this->assertSame($target, $o->targetCostPerResultMicros);
        }

        $this->account->update(['target_cost_per_result_micros' => 5_000_000]);
        $this->assertSame(GoogleAdsCplStatus::Worse, $this->overview()->targetStatus);

        $this->account->update(['target_cost_per_result_micros' => null]);
        $this->assertSame(GoogleAdsCplStatus::NoTarget, $this->overview()->targetStatus);

        // No result type: a target exists but there is no current cost per result => null status.
        $this->account->update(['target_cost_per_result_micros' => 10_000_000, 'result_action_type' => null]);
        $this->assertNull($this->overview()->targetStatus);
    }

    public function test_budget_overview_lists_campaign_budgets_apart_from_the_monthly_target(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental', ['daily_budget_minor' => 2500]);
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 10_000_000, 2);
        $this->account->update(['monthly_budget_target_micros' => 600_000_000, 'target_cost_per_result_micros' => 5_000_000]);

        $b = app(MetaAdsBudgetReader::class)->read($this->account->fresh());

        $this->assertSame('USD', $b->currencyCode);
        $this->assertSame(600_000_000, $b->pacing->monthlyTargetMicros);
        $this->assertSame(5_000_000, $b->targetCostPerResultMicros);
        $this->assertSame(5_000_000, $b->currentCostPerResultMicros);
        $this->assertSame(GoogleAdsCplStatus::OnTarget, $b->costPerResultStatus);
        $this->assertTrue($b->resultTypeChosen);
        $this->assertCount(1, $b->campaigns);
        $this->assertSame(2500, $b->campaigns[0]->dailyBudgetMinor);   // Meta's own daily budget, not the 600 monthly target
        $this->assertSame(30_000_000, $b->campaigns[0]->spendMicros());
    }

    // ---- money ----------------------------------------------------------------------------------------

    public function test_money_is_micros_and_a_zero_decimal_currency_budget_uses_minor_units(): void
    {
        $this->account->update(['currency_code' => 'JPY']);
        $campaign = $this->seedMetaCampaign($this->account, 'Tokyo', ['daily_budget_minor' => 5000]);   // JPY 5,000 (no minor unit)
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-02', 4_000_000_000, 2);

        $o = $this->overview();
        $this->assertSame('JPY', $o->currencyCode);
        $this->assertSame(8_000_000_000, $o->spendMicros);
        $this->assertSame('JPY 8,000', MetaAdsMoney::format($o->spendMicros, $o->currencyCode));
        $this->assertSame(2_000_000_000, $o->costPerResultMicros);

        $facts = app(\App\Library\MetaAds\Reporting\MetaAdsCampaignReader::class)->budgetFacts(
            app(\App\Library\MetaAds\Reporting\MetaAdsCampaignReader::class)->find($this->account->fresh(), $campaign->uid, MetaAdsPeriod::resolve('last_30', $this->account)),
        );
        $this->assertSame(5_000_000_000, $facts['daily_budget_micros']);           // 5,000 JPY, not 50
        $this->assertSame('JPY', $facts['currency_code']);
        $this->assertSame('daily', $facts['budget_type']);
        $this->assertSame(4_000_000_000, $facts['average_daily_spend_micros']);
        $this->assertSame(0.8, $facts['budget_utilisation']);
    }

    public function test_usd_budget_minor_units_are_cents(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental', ['daily_budget_minor' => 2500, 'lifetime_budget_minor' => null]);
        $row = app(\App\Library\MetaAds\Reporting\MetaAdsCampaignReader::class)->find($this->account, $campaign->uid, MetaAdsPeriod::resolve('last_30', $this->account));
        $facts = app(\App\Library\MetaAds\Reporting\MetaAdsCampaignReader::class)->budgetFacts($row);

        $this->assertSame(25_000_000, $facts['daily_budget_micros']);              // $25.00
        $this->assertNull($facts['lifetime_budget_micros']);
        $this->assertNull($facts['average_daily_spend_micros']);                   // no rows: null, not 0
        $this->assertNull($facts['budget_utilisation']);
        $this->assertSame('daily', $facts['budget_type']);
    }

    // ---- trend ----------------------------------------------------------------------------------------

    public function test_trend_series_gap_rules(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaInsight($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', 10_000_000, 1000, 20, 8);
        $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', 2);
        $this->seedMetaInsight($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-03', 6_000_000, 500, 10, null);   // spend, no result row, null link clicks
        // 2026-10-02 has no row at all.

        $payload = app(MetaAdsTrendSeries::class)->forPeriod($this->account->fresh(), MetaAdsPeriod::resolve('last_7', $this->account));
        $s = $payload['series'];

        $this->assertCount(7, $payload['labels']);
        $this->assertTrue($payload['has_data']);
        $this->assertTrue($payload['result_type_chosen']);
        $this->assertTrue($payload['has_result_data']);
        $this->assertSame('USD', $payload['currency']);
        // Sep 28 .. Oct 4: index 3 = Oct 1, 4 = Oct 2, 5 = Oct 3.
        $this->assertSame([10_000_000, 0, 6_000_000], array_slice($s['spend_micros'], 3, 3));
        $this->assertSame([2.0, null, 0.0], array_slice($s['results'], 3, 3));           // real zero on a spend day; null on a missing day
        $this->assertSame([5.0, null, null], array_slice($s['cost_per_result'], 3, 3));  // never a $0 cost per result
        $this->assertSame([8, null, null], array_slice($s['link_clicks'], 3, 3));        // null link clicks stays null
        $this->assertNull($s['results'][0]);                                             // before any data
    }

    public function test_trend_series_without_result_type_and_for_a_foreign_campaign_uid(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Rental');
        $this->seedMetaInsight($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', 10_000_000);
        $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', 2);
        $this->account->update(['result_action_type' => null]);

        $period = MetaAdsPeriod::resolve('last_7', $this->account);
        $payload = app(MetaAdsTrendSeries::class)->forPeriod($this->account->fresh(), $period);

        $this->assertFalse($payload['result_type_chosen']);
        $this->assertSame(array_fill(0, 7, null), $payload['series']['results']);
        $this->assertSame(array_fill(0, 7, null), $payload['series']['cost_per_result']);
        $this->assertSame(10_000_000, $payload['series']['spend_micros'][3]);

        $unknown = app(MetaAdsTrendSeries::class)->forPeriod($this->account->fresh(), $period, '00000000-0000-0000-0000-000000000000');
        $this->assertFalse($unknown['has_data']);
        $this->assertSame(array_fill(0, 7, 0), $unknown['series']['spend_micros']);
    }
}
