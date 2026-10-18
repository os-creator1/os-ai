<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFactReader;
use App\Library\MetaAds\Reporting\MetaAdsAdReader;
use App\Library\MetaAds\Reporting\MetaAdsAdSetReader;
use App\Library\MetaAds\Reporting\MetaAdsBudgetReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsOverviewReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\MetaAds\Reporting\MetaAdsTrendSeries;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\TestCase;

/**
 * Bounded work: every page-reader's query count is a small constant that does
 * NOT grow with the number of campaigns / ad sets / ads (no N+1). The same
 * measurements are taken with 3 / 6 / 10 entities and again with 30 / 60 / 100
 * and must be identical.
 */
class MetaAdsQueryBoundsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAdsReportingData;

    private MetaAdsAccount $account;

    /** @var array<int, MetaAdsCampaign> */
    private array $campaigns = [];

    /** @var array<int, MetaAdsAdSet> */
    private array $adSets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinMetaClock();
        [, $business] = $this->metaReportingTenant();
        $this->account = $this->metaReportingAccountFor($business, [
            'target_cost_per_result_micros' => 10_000_000,
            'monthly_budget_target_micros' => 500_000_000,
        ]);
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    /** Seeds N campaigns, M ad sets (spread over the campaigns) and K ads (spread over the ad sets). */
    private function seedScale(int $campaigns, int $adSets, int $ads, bool $withIssues): void
    {
        $newCampaigns = [];
        for ($i = 1; $i <= $campaigns; $i++) {
            $newCampaigns[] = $this->seedMetaCampaign($this->account, 'Campaign ' . count($this->campaigns) . '-' . $i, [
                'effective_status' => $withIssues && $i === 1 ? 'WITH_ISSUES' : 'ACTIVE',
            ]);
        }

        $newSets = [];
        for ($i = 1; $i <= $adSets; $i++) {
            $newSets[] = $this->seedMetaAdSet($newCampaigns[($i - 1) % $campaigns], 'Set ' . count($this->adSets) . '-' . $i, [
                'frequency_7d' => $withIssues && $i === 1 ? '4.0000' : '1.5000',
                'effective_status' => $withIssues && $i === 2 ? 'DISAPPROVED' : 'ACTIVE',
            ]);
        }

        $newAds = [];
        for ($i = 1; $i <= $ads; $i++) {
            $set = $newSets[($i - 1) % $adSets];
            $newAds[] = $this->seedMetaAd($set, 'Ad ' . $i, [
                'effective_status' => $withIssues && $i === 3 ? 'WITH_ISSUES' : 'ACTIVE',
                'creative_thumbnail_url' => 'https://scontent.xx.fbcdn.net/v/t/' . $i . '.jpg',
            ]);
        }

        $insights = [];
        $results = [];
        $now = now()->toDateTimeString();
        $push = function (MetaAdsLevel $level, int $entityId, string $date, int $spend, int $result) use (&$insights, &$results, $now): void {
            $insights[] = [
                'business_id' => $this->account->business_id, 'meta_ads_account_id' => $this->account->id,
                'level' => $level->value, 'entity_id' => $entityId, 'metric_date' => $date,
                'spend_micros' => $spend, 'impressions' => 1000, 'clicks' => 20, 'link_clicks' => 9,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $results[] = [
                'business_id' => $this->account->business_id, 'meta_ads_account_id' => $this->account->id,
                'level' => $level->value, 'entity_id' => $entityId, 'metric_date' => $date,
                'action_type' => self::RESULT_TYPE, 'results' => $result, 'result_value' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        };

        foreach ($newCampaigns as $campaign) {
            foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $date) {
                $push(MetaAdsLevel::Campaign, $campaign->id, $date, 12_000_000, 1);
            }
        }

        foreach ($newSets as $index => $set) {
            // The 14-day window the fatigue rule reads (previous 7 days cheap, last 7 days costly).
            for ($d = 0; $d < 14; $d++) {
                $date = \Carbon\CarbonImmutable::parse('2026-09-21')->addDays($d)->format('Y-m-d');
                $push(MetaAdsLevel::AdSet, $set->id, $date, $d < 7 ? 10_000_000 : 30_000_000, $d % 7 === 0 ? 1 : 0);
            }
        }

        foreach ($newAds as $ad) {
            foreach (['2026-10-01', '2026-10-02'] as $date) {
                $push(MetaAdsLevel::Ad, $ad->id, $date, 5_000_000, 1);
            }
        }

        foreach (array_chunk($insights, 500) as $chunk) {
            DB::table('meta_ads_daily_insights')->insert($chunk);
        }

        foreach (array_chunk($results, 500) as $chunk) {
            DB::table('meta_ads_daily_results')->insert($chunk);
        }

        $this->campaigns = array_merge($this->campaigns, $newCampaigns);
        $this->adSets = array_merge($this->adSets, $newSets);
    }

    /** @return array<string, int> query counts per reader call */
    private function measure(): array
    {
        $account = $this->account->fresh();
        $period = MetaAdsPeriod::resolve('last_30', $account);
        $campaignUid = $this->campaigns[0]->uid;
        $adSetUid = $this->adSets[0]->uid;
        $adUid = MetaAdsAd::query()->where('meta_ads_account_id', $account->id)->value('uid');

        $calls = [
            'campaign_page' => fn () => app(MetaAdsCampaignReader::class)->page($account, $period, null, 'cost_per_result', 'asc', 1, 100),
            'campaign_find' => fn () => app(MetaAdsCampaignReader::class)->find($account, $campaignUid, $period),
            'campaign_detail' => fn () => app(MetaAdsCampaignReader::class)->detail($account, $campaignUid, $period),
            'adset_page' => fn () => app(MetaAdsAdSetReader::class)->page($account, $period, null, null, 'frequency', 'desc', 1, 100),
            'adset_find' => fn () => app(MetaAdsAdSetReader::class)->find($account, $adSetUid, $period),
            'ad_page' => fn () => app(MetaAdsAdReader::class)->page($account, $period, null, null, null, 'ctr', 'desc', 1, 100),
            'ad_find' => fn () => app(MetaAdsAdReader::class)->find($account, $adUid, $period),
            'overview' => fn () => app(MetaAdsOverviewReader::class)->read($account, $period),
            'budget' => fn () => app(MetaAdsBudgetReader::class)->read($account),
            'trend' => fn () => app(MetaAdsTrendSeries::class)->forPeriod($account, $period, $campaignUid),
            'facts' => fn () => app(MetaAdsRecommendationFactReader::class)->facts($account, $period),
        ];

        $counts = [];
        foreach ($calls as $name => $call) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $call();
            $counts[$name] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    public function test_query_counts_are_small_and_do_not_grow_with_row_counts(): void
    {
        $this->seedScale(3, 6, 10, true);
        $small = $this->measure();

        $this->seedScale(27, 54, 90, false);
        $this->assertSame(30, MetaAdsCampaign::query()->where('meta_ads_account_id', $this->account->id)->count());
        $this->assertSame(60, MetaAdsAdSet::query()->where('meta_ads_account_id', $this->account->id)->count());
        $this->assertSame(100, MetaAdsAd::query()->where('meta_ads_account_id', $this->account->id)->count());
        $large = $this->measure();

        $this->assertSame($small, $large, 'query counts must not depend on the number of entities');

        $bounds = [
            'campaign_page' => 3, 'campaign_find' => 2, 'campaign_detail' => 12,
            'adset_page' => 3, 'adset_find' => 2, 'ad_page' => 3, 'ad_find' => 2,
            'overview' => 8, 'budget' => 8, 'trend' => 4, 'facts' => 16,
        ];

        foreach ($bounds as $name => $max) {
            $this->assertLessThanOrEqual($max, $large[$name], $name . ' used ' . $large[$name] . ' queries');
            $this->assertGreaterThan(0, $large[$name], $name . ' ran no query');
        }
    }

    public function test_pages_with_30_campaigns_return_all_rows_with_correct_totals(): void
    {
        $this->seedScale(30, 60, 100, false);
        $account = $this->account->fresh();
        $period = MetaAdsPeriod::resolve('last_30', $account);

        $campaigns = app(MetaAdsCampaignReader::class)->page($account, $period, null, 'spend', 'desc', 1, 100);
        $this->assertCount(30, $campaigns->items);
        foreach ($campaigns->items as $row) {
            $this->assertSame(60_000_000, $row->spendMicros());
            $this->assertSame('5.000000', $row->totals->results);
            $this->assertSame(12_000_000, $row->costPerResultMicros());
        }

        $adSets = app(MetaAdsAdSetReader::class)->page($account, $period, null, null, 'spend', 'desc', 1, 100);
        $this->assertCount(60, $adSets->items);
        $this->assertSame(7 * 10_000_000 + 7 * 30_000_000, $adSets->items[0]->spendMicros());

        $ads = app(MetaAdsAdReader::class)->page($account, $period, null, null, null, 'spend', 'desc', 1, 100);
        $this->assertSame(100, $ads->total);
        $this->assertCount(100, $ads->items);
        $this->assertSame(10_000_000, $ads->items[0]->spendMicros());

        $overview = app(MetaAdsOverviewReader::class)->read($account, $period);
        $this->assertSame(30 * 60_000_000, $overview->spendMicros);          // campaign rows only
    }
}
