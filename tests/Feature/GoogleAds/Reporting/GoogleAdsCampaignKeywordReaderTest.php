<?php

namespace Tests\Feature\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsKeywordReader;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\GoogleAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\TestCase;

/** Contract §4 / §9 — Campaigns table + detail and the Keywords table, cache-only. */
class GoogleAdsCampaignKeywordReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGoogleAdsReportingData;

    private GoogleAdsAccount $account;

    private GoogleAdsPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinAdsClock();
        [, $business] = $this->adsTenant();
        $this->account = $this->adsAccountFor($business);
        $this->period = GoogleAdsPeriod::resolve('last_30', $this->account);
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    /** @return array<string, \App\Models\GoogleAdsCampaign> */
    private function seedThreeCampaigns(): array
    {
        $a = $this->seedCampaign($this->account, '1000000001', 'Alpha', ['budget_amount_micros' => 4_000_000, 'budget_shared' => true]);
        $b = $this->seedCampaign($this->account, '1000000002', 'Beta');
        $c = $this->seedCampaign($this->account, '1000000003', 'Gamma', ['status' => GoogleAdsEntityStatus::Paused]);
        $d = $this->seedCampaign($this->account, '1000000004', 'Delta idle');

        // Alpha: 30 spent, 3 conv (CPL 10). Beta: 50 spent, 1 conv (CPL 50). Gamma: 20 spent, 0 conv. Delta: no rows.
        $this->seedCampaignDays($this->account, '1000000001', '2026-10-01', '2026-10-03', 10_000_000, 10, '1');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000002', '2026-10-01', 50_000_000, 20, '1');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '1000000003', '2026-10-01', 20_000_000, 10, '0');

        return ['alpha' => $a, 'beta' => $b, 'gamma' => $c, 'delta' => $d];
    }

    public function test_campaign_table_rows_metrics_and_null_for_no_rows(): void
    {
        $this->seedThreeCampaigns();

        $page = app(GoogleAdsCampaignReader::class)->page($this->account, $this->period);

        $this->assertSame(4, $page->total);
        $names = array_map(fn ($r) => $r->name, $page->items);
        $this->assertSame(['Beta', 'Alpha', 'Gamma', 'Delta idle'], $names, 'spend desc, no-row campaign last');

        $alpha = $page->items[1];
        $this->assertSame(30_000_000, $alpha->spendMicros());
        $this->assertSame(30, $alpha->totals->clicks);
        $this->assertSame('3.000000', $alpha->totals->conversions);
        $this->assertSame(10_000_000, $alpha->cplMicros());
        $this->assertEqualsWithDelta(0.1, $alpha->conversionRate(), 1e-9);
        $this->assertSame(4_000_000, $alpha->dailyBudgetMicros);
        $this->assertTrue($alpha->budgetShared);

        $gamma = $page->items[2];
        $this->assertSame(GoogleAdsEntityStatus::Paused, $gamma->status);
        $this->assertSame('0.000000', $gamma->totals->conversions);
        $this->assertNull($gamma->cplMicros());

        $delta = $page->items[3];
        $this->assertNull($delta->spendMicros());
        $this->assertNull($delta->totals->conversions);
        $this->assertFalse($delta->totals->hasData());
    }

    public function test_external_ids_are_only_in_the_internal_field(): void
    {
        $this->seedThreeCampaigns();

        $row = app(GoogleAdsCampaignReader::class)->page($this->account, $this->period)->items[0];

        $this->assertSame(['external_campaign_id' => '1000000002'], $row->internal);
        $public = get_object_vars($row);
        unset($public['internal']);
        $this->assertStringNotContainsString('1000000002', json_encode($public, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function test_sorting_filtering_and_paging(): void
    {
        $this->seedThreeCampaigns();
        $reader = app(GoogleAdsCampaignReader::class);

        $byCpl = array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period, null, 'cpl', 'asc')->items);
        $this->assertSame(['Alpha', 'Beta'], array_slice($byCpl, 0, 2));
        $this->assertContains('Gamma', array_slice($byCpl, 2), 'null CPL rows sort last');

        $byCplDesc = array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period, null, 'cpl', 'desc')->items);
        $this->assertSame(['Beta', 'Alpha'], array_slice($byCplDesc, 0, 2));

        $this->assertSame(['Alpha', 'Beta', 'Delta idle', 'Gamma'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period, null, 'name', 'asc')->items));
        $this->assertSame(['Gamma'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period, 'paused')->items));
        $this->assertSame(3, $reader->page($this->account, $this->period, 'ENABLED')->total);
        $this->assertSame(4, $reader->page($this->account, $this->period, 'bogus')->total, 'unknown status filter = all');

        $second = $reader->page($this->account, $this->period, null, 'name', 'asc', 2, 3);
        $this->assertSame(4, $second->total);
        $this->assertSame(2, $second->lastPage);
        $this->assertSame(['Gamma'], array_map(fn ($r) => $r->name, $second->items));
        $this->assertCount(4, $reader->page($this->account, $this->period, null, 'conversion_rate', 'desc')->items);
        $this->assertCount(4, $reader->page($this->account, $this->period, null, 'garbage', 'sideways')->items, 'unknown sort/direction fall back safely');
    }

    public function test_period_changes_only_filter_cached_rows(): void
    {
        $this->seedThreeCampaigns();
        $reader = app(GoogleAdsCampaignReader::class);

        $prev = GoogleAdsPeriod::resolve('previous_month', $this->account);
        $this->assertTrue(collect($reader->page($this->account, $prev)->items)->every(fn ($r) => $r->spendMicros() === null));
    }

    public function test_campaign_lookup_is_by_uid_inside_the_account(): void
    {
        $campaigns = $this->seedThreeCampaigns();
        $reader = app(GoogleAdsCampaignReader::class);

        $this->assertSame('Alpha', $reader->find($this->account, $campaigns['alpha']->uid, $this->period)->name);
        $this->assertNull($reader->find($this->account, '00000000-0000-0000-0000-000000000000', $this->period));
        $this->assertNull($reader->detail($this->account, 'nope', $this->period));
    }

    public function test_campaign_detail_trend_ad_groups_keywords_search_terms_and_budget_facts(): void
    {
        $campaigns = $this->seedThreeCampaigns();
        $alpha = $campaigns['alpha'];
        $g1 = $this->seedAdGroup($alpha, '3000000001', 'General');
        $g2 = $this->seedAdGroup($alpha, '3000000002', 'Corporate');
        $this->seedAdGroup($alpha, '3000000003', 'Empty group');
        $this->seedKeyword($alpha, $g1, '3000000001~11', 'photo booth rental', ['quality_score' => 8]);
        $this->seedKeyword($alpha, $g2, '3000000002~12', 'corporate booth');
        $this->seedKeyword($alpha, null, '1000000001~99', 'free', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);
        $this->seedCampaignKeywordRows('3000000001~11', 20_000_000, 2);
        $this->seedCampaignKeywordRows('3000000002~12', 10_000_000, 1);
        $this->seedSearchTerm($alpha, $g1, 'photo booth hire', '2026-10-02', 7_000_000, 4, '1');

        $detail = app(GoogleAdsCampaignReader::class)->detail($this->account, $alpha->uid, $this->period);

        $this->assertSame('Alpha', $detail->campaign->name);
        $this->assertSame(30_000_000, array_sum($detail->trend['series']['spend_micros']));

        $groups = collect($detail->adGroups)->keyBy('name');
        $this->assertSame(20_000_000, $groups['General']['totals']->spendMicros);
        $this->assertSame(10_000_000, $groups['Corporate']['totals']->spendMicros);
        $this->assertNull($groups['Empty group']['totals']->spendMicros);
        $this->assertSame('General', $detail->adGroups[0]['name'], 'ad groups ordered by spend');

        $this->assertSame(2, $detail->keywords->total, 'negatives are not in the keyword table');
        $this->assertSame(1, $detail->searchTerms->total);

        $this->assertSame(4_000_000, $detail->budget['daily_budget_micros']);
        $this->assertTrue($detail->budget['budget_shared']);
        $this->assertSame(10_000_000, $detail->budget['average_daily_spend_micros']);
        $this->assertSame(3, $detail->budget['days_with_data']);
        $this->assertEqualsWithDelta(2.5, $detail->budget['budget_utilisation'], 1e-6);
    }

    private function seedCampaignKeywordRows(string $key, int $totalCost, int $conversions): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $i => $date) {
            $this->seedMetric($this->account, GoogleAdsMetricLevel::Keyword, $key, $date, intdiv($totalCost, 3) + ($i === 0 ? $totalCost % 3 : 0), 5, $i === 0 ? (string) $conversions : '0');
        }
    }

    public function test_keyword_table_metrics_quality_score_filters_and_sorting(): void
    {
        $alpha = $this->seedCampaign($this->account, '1000000001', 'Alpha');
        $beta = $this->seedCampaign($this->account, '1000000002', 'Beta');
        $g1 = $this->seedAdGroup($alpha, '3000000001', 'General');
        $g2 = $this->seedAdGroup($beta, '3000000002', 'Beta group');
        $this->seedKeyword($alpha, $g1, '3000000001~11', 'photo booth rental', ['quality_score' => 8]);
        $this->seedKeyword($alpha, $g1, '3000000001~12', 'booth hire', ['status' => GoogleAdsEntityStatus::Paused]);
        $this->seedKeyword($beta, $g2, '3000000002~21', 'wedding booth');
        $this->seedKeyword($alpha, $g1, '3000000001~13', 'no rows keyword');
        $this->seedKeyword($alpha, null, '1000000001~99', 'free', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);
        $this->seedKeyword($alpha, $g1, '3000000001~98', 'jobs', ['is_negative' => true]);

        $this->seedMetric($this->account, GoogleAdsMetricLevel::Keyword, '3000000001~11', '2026-10-01', 12_000_000, 6, '3');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Keyword, '3000000001~12', '2026-10-01', 9_000_000, 3, '0');
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Keyword, '3000000002~21', '2026-10-01', 30_000_000, 10, '1');
        // A campaign-level row sharing no key must never leak into keyword rows.
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, '3000000001~11', '2026-10-01', 777_000_000, 1, '1');

        $reader = app(GoogleAdsKeywordReader::class);
        $page = $reader->page($this->account, $this->period);

        $this->assertSame(4, $page->total, 'negatives are excluded from the performance table');
        $this->assertSame(['wedding booth', 'photo booth rental', 'booth hire', 'no rows keyword'], array_map(fn ($r) => $r->text, $page->items));

        $rental = $page->items[1];
        $this->assertSame(12_000_000, $rental->totals->spendMicros);
        $this->assertSame(4_000_000, $rental->cplMicros());
        $this->assertEqualsWithDelta(0.5, $rental->conversionRate(), 1e-9);
        $this->assertSame(8, $rental->qualityScore);
        $this->assertSame('Alpha', $rental->campaignName);
        $this->assertSame('General', $rental->adGroupName);
        $this->assertNull($page->items[0]->qualityScore, 'quality score only when stored');
        $this->assertNull($page->items[3]->totals->spendMicros);
        $this->assertNull($page->items[2]->cplMicros(), 'zero conversions => null CPL');

        $this->assertSame(3, $reader->page($this->account, $this->period, $alpha->uid)->total);
        $this->assertSame(['booth hire'], array_map(fn ($r) => $r->text, $reader->page($this->account, $this->period, null, 'paused')->items));
        $this->assertSame('booth hire', $reader->page($this->account, $this->period, null, null, 'keyword', 'asc')->items[0]->text);

        $negatives = $reader->negatives($this->account);
        $this->assertSame(['free', 'jobs'], array_map(fn ($r) => $r->text, $negatives));
        $this->assertTrue($negatives[0]->isNegative);
        $this->assertSame(GoogleAdsKeywordLevel::Campaign, $negatives[0]->level);
        $this->assertCount(2, $reader->negatives($this->account, $alpha->uid));
        $this->assertCount(0, $reader->negatives($this->account, $beta->uid));
    }
}
