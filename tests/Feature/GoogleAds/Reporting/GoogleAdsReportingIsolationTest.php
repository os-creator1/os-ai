<?php

namespace Tests\Feature\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFactReader;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationPresenter;
use App\Library\GoogleAds\Reporting\GoogleAdsBudgetReader;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsKeywordReader;
use App\Library\GoogleAds\Reporting\GoogleAdsOverviewReader;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReader;
use App\Library\GoogleAds\Reporting\GoogleAdsTrendSeries;
use App\Models\GoogleAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\TestCase;

/**
 * Business isolation, currency separation and bounded query counts. Two
 * Businesses are seeded with COLLIDING external ids on purpose: the readers
 * must separate them by business_id + google_ads_account_id alone.
 */
class GoogleAdsReportingIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGoogleAdsReportingData;

    private GoogleAdsAccount $usd;

    private GoogleAdsAccount $gbp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinAdsClock();

        [, $a] = $this->adsTenant('Business A');
        [, $b] = $this->adsTenant('Business B');
        $this->usd = $this->adsAccountFor($a, ['currency_code' => 'USD', 'target_cpl_micros' => 10_000_000, 'monthly_budget_target_micros' => 300_000_000]);
        $this->gbp = $this->adsAccountFor($b, ['currency_code' => 'GBP', 'customer_id' => '1234567890', 'time_zone' => 'Europe/London', 'target_cpl_micros' => 10_000_000, 'monthly_budget_target_micros' => 300_000_000]);

        // Identical external ids, names, criterion ids and search terms in both accounts.
        foreach ([[$this->usd, 10_000_000, 'A-camp'], [$this->gbp, 70_000_000, 'B-camp']] as [$account, $cost, $name]) {
            $campaign = $this->seedCampaign($account, '1000000001', $name);
            $group = $this->seedAdGroup($campaign, '3000000001', $name . ' group');
            $this->seedKeyword($campaign, $group, '3000000001~11', $name . ' keyword');
            $this->seedKeyword($campaign, null, '1000000001~99', 'free', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);
            $this->seedCampaignDays($account, '1000000001', '2026-10-01', '2026-10-03', $cost, 10, '1');
            $this->seedMetric($account, GoogleAdsMetricLevel::Keyword, '3000000001~11', '2026-10-02', $cost, 10, '1');
            $this->seedSearchTerm($campaign, $group, 'shared search term', '2026-10-02', $cost * 5, 9, '0');
        }
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    public function test_business_a_rows_never_appear_in_business_b_readers(): void
    {
        $periodA = GoogleAdsPeriod::resolve('last_30', $this->usd);
        $periodB = GoogleAdsPeriod::resolve('last_30', $this->gbp);

        // Overview
        $overview = app(GoogleAdsOverviewReader::class);
        $this->assertSame(30_000_000, $overview->read($this->usd, $periodA)->spendMicros);
        $this->assertSame(210_000_000, $overview->read($this->gbp, $periodB)->spendMicros);

        // Trend
        $trend = app(GoogleAdsTrendSeries::class);
        $this->assertSame(30_000_000, array_sum($trend->forPeriod($this->usd, $periodA)['series']['spend_micros']));
        $this->assertSame(210_000_000, array_sum($trend->forPeriod($this->gbp, $periodB)['series']['spend_micros']));

        // Campaigns
        $campaigns = app(GoogleAdsCampaignReader::class);
        $a = $campaigns->page($this->usd, $periodA);
        $b = $campaigns->page($this->gbp, $periodB);
        $this->assertSame(['A-camp'], array_map(fn ($r) => $r->name, $a->items));
        $this->assertSame(['B-camp'], array_map(fn ($r) => $r->name, $b->items));
        $this->assertSame(30_000_000, $a->items[0]->spendMicros());
        $this->assertSame(210_000_000, $b->items[0]->spendMicros());

        // A campaign uid of Business B does not resolve under Business A's account (and vice versa).
        $this->assertNull($campaigns->find($this->usd, $b->items[0]->uid, $periodA));
        $this->assertNull($campaigns->detail($this->usd, $b->items[0]->uid, $periodA));
        $this->assertNull($campaigns->find($this->gbp, $a->items[0]->uid, $periodB));

        // Keywords (colliding criterion id) and negatives
        $keywords = app(GoogleAdsKeywordReader::class);
        $ka = $keywords->page($this->usd, $periodA);
        $kb = $keywords->page($this->gbp, $periodB);
        $this->assertSame(['A-camp keyword'], array_map(fn ($r) => $r->text, $ka->items));
        $this->assertSame(['B-camp keyword'], array_map(fn ($r) => $r->text, $kb->items));
        $this->assertSame(10_000_000, $ka->items[0]->totals->spendMicros);
        $this->assertSame(70_000_000, $kb->items[0]->totals->spendMicros);
        $this->assertCount(1, $keywords->negatives($this->usd));
        $this->assertSame([], $keywords->page($this->usd, $periodA, $b->items[0]->uid)->items, 'foreign campaign uid filters to nothing');

        // Search terms (same term hash in both)
        $terms = app(GoogleAdsSearchTermReader::class);
        $ta = $terms->page($this->usd, $periodA);
        $tb = $terms->page($this->gbp, $periodB);
        $this->assertSame(1, $ta->total);
        $this->assertSame(50_000_000, $ta->items[0]->totals->spendMicros);
        $this->assertSame(350_000_000, $tb->items[0]->totals->spendMicros);
        $this->assertSame('A-camp', $ta->items[0]->campaignName);
        $this->assertSame('B-camp', $tb->items[0]->campaignName);

        // Budget
        $budget = app(GoogleAdsBudgetReader::class);
        $this->assertSame(30_000_000, $budget->pacing($this->usd)->spentMicros);
        $this->assertSame(210_000_000, $budget->pacing($this->gbp)->spentMicros);
    }

    public function test_a_negative_in_one_business_never_marks_another_business_term(): void
    {
        $terms = app(GoogleAdsSearchTermReader::class);
        $period = GoogleAdsPeriod::resolve('last_30', $this->gbp);
        $this->seedKeyword(\App\Models\GoogleAdsCampaign::query()->where('google_ads_account_id', $this->usd->id)->first(), null, '1000000001~50', 'shared search term', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);

        $this->assertTrue($terms->page($this->usd, GoogleAdsPeriod::resolve('last_30', $this->usd))->items[0]->alreadyNegative);
        $this->assertFalse($terms->page($this->gbp, $period)->items[0]->alreadyNegative);
    }

    public function test_currency_is_never_mixed_and_formatting_uses_the_account_currency(): void
    {
        $overview = app(GoogleAdsOverviewReader::class);
        $usd = $overview->read($this->usd);
        $gbp = $overview->read($this->gbp);

        $this->assertSame('USD', $usd->currencyCode);
        $this->assertSame('GBP', $gbp->currencyCode);
        $this->assertSame('USD 30.00', \App\Library\GoogleAds\GoogleAdsMoney::format($usd->spendMicros, $usd->currencyCode));
        $this->assertSame('GBP 210.00', \App\Library\GoogleAds\GoogleAdsMoney::format($gbp->spendMicros, $gbp->currencyCode));
        $this->assertSame('GBP', app(GoogleAdsTrendSeries::class)->forPeriod($this->gbp, GoogleAdsPeriod::resolve(null, $this->gbp))['currency']);

        $presenter = new GoogleAdsRecommendationPresenter();
        $facts = app(GoogleAdsRecommendationFactReader::class)->facts($this->gbp);
        $this->assertNotEmpty($facts);
        foreach ($facts as $fact) {
            $this->assertSame('GBP', $fact->currencyCode);
            $copy = $presenter->present($fact);
            $text = implode(' ', array_merge([$copy['title']], $copy['evidence_lines']));
            $this->assertStringNotContainsString('USD', $text);
        }
    }

    public function test_readers_run_a_bounded_number_of_queries_independent_of_row_count(): void
    {
        $period = GoogleAdsPeriod::resolve('last_30', $this->usd);
        $measure = function () use ($period): array {
            $count = fn (callable $work): int => $this->queryCount($work);

            return [
                'overview' => $count(fn () => app(GoogleAdsOverviewReader::class)->read($this->usd, $period)),
                'trend' => $count(fn () => app(GoogleAdsTrendSeries::class)->forPeriod($this->usd, $period)),
                'campaigns' => $count(fn () => app(GoogleAdsCampaignReader::class)->page($this->usd, $period)),
                'keywords' => $count(fn () => app(GoogleAdsKeywordReader::class)->page($this->usd, $period)),
                'terms' => $count(fn () => app(GoogleAdsSearchTermReader::class)->page($this->usd, $period)),
                'waste' => $count(fn () => app(GoogleAdsSearchTermReader::class)->wasteSummary($this->usd, $period)),
                'budget' => $count(fn () => app(GoogleAdsBudgetReader::class)->read($this->usd, $period)),
                'facts' => $count(fn () => app(GoogleAdsRecommendationFactReader::class)->facts($this->usd, $period)),
            ];
        };

        $small = $measure();

        // 15 more campaigns with 20 days each, keywords and many search terms.
        for ($i = 2; $i <= 16; $i++) {
            $campaign = $this->seedCampaign($this->usd, (string) (1000000000 + $i), 'Extra ' . $i);
            $group = $this->seedAdGroup($campaign, (string) (3000000000 + $i), 'Extra group ' . $i);
            $this->seedKeyword($campaign, $group, "{$group->external_ad_group_id}~1", 'extra keyword ' . $i);
            $this->seedCampaignDays($this->usd, (string) (1000000000 + $i), '2026-09-14', '2026-10-03', 3_000_000, 4, $i % 2 === 0 ? '0' : '1');
            $this->seedMetric($this->usd, GoogleAdsMetricLevel::Keyword, "{$group->external_ad_group_id}~1", '2026-10-01', 1_000_000);
            foreach (range(1, 5) as $t) {
                $this->seedSearchTerm($campaign, $group, "term {$i} {$t}", '2026-10-01', 30_000_000, 7, '0');
            }
        }

        $large = $measure();

        $this->assertSame($small, $large, 'query counts must not grow with rows: ' . json_encode([$small, $large]));
        $ceilings = ['overview' => 6, 'trend' => 2, 'campaigns' => 3, 'keywords' => 3, 'terms' => 4, 'waste' => 4, 'budget' => 6, 'facts' => 12];
        foreach ($ceilings as $reader => $ceiling) {
            $this->assertLessThanOrEqual($ceiling, $large[$reader], "$reader query count {$large[$reader]}");
            $this->assertGreaterThan(0, $large[$reader], $reader);
        }
    }

    private function queryCount(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
