<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\Reporting\MetaAdsPerformanceTree;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\TestCase;

/**
 * The Performance table's Campaign -> Ad set -> Ad tree: each level carries its
 * OWN cached metrics (never summed from children), childless parents and
 * missing metrics stay empty, the totals row counts campaigns only, and
 * another Business's rows never appear. Clock pinned to 2026-10-04 12:00 UTC.
 */
class MetaAdsPerformanceTreeTest extends TestCase
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

    public function test_tree_nests_ad_sets_and_ads_with_their_own_metrics_and_no_double_counting(): void
    {
        $alpha = $this->seedMetaCampaign($this->account, 'Alpha');
        $empty = $this->seedMetaCampaign($this->account, 'Empty');
        $setOne = $this->seedMetaAdSet($alpha, 'Set one');
        $setTwo = $this->seedMetaAdSet($alpha, 'Set two');   // an ad set without ads
        $adOne = $this->seedMetaAd($setOne, 'Ad one');
        $this->seedMetaAd($setOne, 'Ad two');                // an ad without metrics

        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $alpha->id, '2026-10-01', '2026-10-03', 10_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $setOne->id, '2026-10-01', '2026-10-03', 4_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::Ad, $adOne->id, '2026-10-01', '2026-10-03', 1_000_000, 1);

        $period = MetaAdsPeriod::resolve('last_30', $this->account);
        $tree = app(MetaAdsPerformanceTree::class)->page($this->account, $period, null, 'spend', 'desc', 1);

        $this->assertSame(['Alpha', 'Empty'], array_map(fn ($r) => $r->name, $tree['result']->items));
        $this->assertSame(30_000_000, $tree['result']->items[0]->spendMicros());

        $sets = $tree['adSetsByCampaign'][$alpha->uid];
        $this->assertCount(2, $sets);
        $this->assertSame('Set one', $sets[0]->name);                    // spend-descending
        $this->assertSame(12_000_000, $sets[0]->spendMicros());          // its own rows, not a share of the campaign's
        $this->assertNull($sets[1]->spendMicros());                      // no rows => null, not 0
        $this->assertArrayNotHasKey($empty->uid, $tree['adSetsByCampaign']);

        $ads = $tree['adsByAdSet'][$setOne->uid];
        $this->assertCount(2, $ads);
        $this->assertSame(3_000_000, $ads[0]->spendMicros());
        $this->assertNull($ads[1]->spendMicros());
        $this->assertArrayNotHasKey($setTwo->uid, $tree['adsByAdSet']);

        $this->assertFalse($tree['adSetsTruncated']);
        $this->assertFalse($tree['adsTruncated']);
        $this->assertSame(30_000_000, $tree['totals']['spendMicros']);   // campaign level only
        $this->assertSame(2, $tree['totals']['campaignCount']);
    }

    public function test_status_filter_applies_to_campaigns_and_another_business_never_leaks(): void
    {
        $this->seedMetaCampaign($this->account, 'Live');
        $this->seedMetaCampaign($this->account, 'Paused one', ['status' => 'PAUSED']);

        [, $otherBusiness] = $this->metaReportingTenant('Other Booth Co');
        $otherAccount = $this->metaReportingAccountFor($otherBusiness);
        $foreign = $this->seedMetaCampaign($otherAccount, 'Foreign');
        $this->seedMetaAdSet($foreign, 'Foreign set');

        $period = MetaAdsPeriod::resolve('last_30', $this->account);
        $tree = app(MetaAdsPerformanceTree::class)->page($this->account, $period, 'paused', 'spend', 'desc', 1);

        $this->assertSame(['Paused one'], array_map(fn ($r) => $r->name, $tree['result']->items));
        $this->assertSame([], $tree['adSetsByCampaign']);
        $this->assertSame(1, $tree['totals']['campaignCount']);
        $this->assertNull($tree['totals']['spendMicros']);               // no rows => em dash, not 0
    }

    public function test_totals_are_exact_for_the_whole_filtered_set_not_just_the_visible_page(): void
    {
        // 26 active campaigns (one more than a page) at 3 days x 1 unit spend each, plus one paused campaign.
        for ($i = 1; $i <= 26; $i++) {
            $campaign = $this->seedMetaCampaign($this->account, 'Camp ' . $i, ['daily_budget_minor' => 1000]);
            $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 1_000_000, 1);
        }
        $paused = $this->seedMetaCampaign($this->account, 'Paused', ['status' => 'PAUSED', 'daily_budget_minor' => 5000]);
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $paused->id, '2026-10-01', '2026-10-03', 9_000_000, 1);

        $period = MetaAdsPeriod::resolve('last_30', $this->account);
        $reader = app(MetaAdsPerformanceTree::class);

        $all = $reader->page($this->account, $period, null, 'spend', 'desc', 1);
        $this->assertCount(25, $all['result']->items);                    // one page ...
        $this->assertSame(27, $all['totals']['campaignCount']);           // ... but the totals cover every match
        $this->assertSame(26 * 3_000_000 + 27_000_000, $all['totals']['spendMicros']);
        $this->assertSame(26_000, $all['totals']['dailyBudgetMinor']);    // active campaigns' daily budgets only

        $active = $reader->page($this->account, $period, 'active', 'spend', 'desc', 2);
        $this->assertSame(26, $active['totals']['campaignCount']);
        $this->assertSame(26 * 3_000_000, $active['totals']['spendMicros']);

        $pausedOnly = $reader->page($this->account, $period, 'paused', 'spend', 'desc', 1);
        $this->assertSame(27_000_000, $pausedOnly['totals']['spendMicros']);
        $this->assertNull($pausedOnly['totals']['dailyBudgetMinor']);     // a paused budget is not being spent
    }

    public function test_the_tree_costs_a_constant_number_of_queries_however_many_rows_it_holds(): void
    {
        $seed = function (int $campaigns): void {
            for ($i = 1; $i <= $campaigns; $i++) {
                $campaign = $this->seedMetaCampaign($this->account, 'Bulk ' . $campaigns . '-' . $i);
                $set = $this->seedMetaAdSet($campaign, 'Set ' . $i);
                $ad = $this->seedMetaAd($set, 'Ad ' . $i);
                $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 1_000_000, 1);
                $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $set->id, '2026-10-01', '2026-10-03', 1_000_000, 1);
                $this->seedMetaDays($this->account, MetaAdsLevel::Ad, $ad->id, '2026-10-01', '2026-10-03', 1_000_000, 1);
            }
        };
        $period = MetaAdsPeriod::resolve('last_30', $this->account);
        $count = function () use ($period): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(MetaAdsPerformanceTree::class)->page($this->account, $period, null, 'spend', 'desc', 1);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $seed(2);
        $small = $count();
        $seed(15);
        $large = $count();

        $this->assertSame($small, $large, 'the number of queries must not grow with the number of campaigns, ad sets or ads');
        $this->assertLessThanOrEqual(16, $large);
    }

    public function test_the_aggregate_cost_per_result_rounds_half_up_and_is_null_without_results(): void
    {
        $period = MetaAdsPeriod::resolve('last_30', $this->account);

        $withResults = $this->seedMetaCampaign($this->account, 'Fractional');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $withResults->id, '2026-10-01', '2026-10-01', 20_000_000, 3);   // 20M over 3 results

        $tree = app(MetaAdsPerformanceTree::class)->page($this->account, $period, null, 'spend', 'desc', 1);
        $this->assertSame(6_666_667, $tree['totals']['costPerResultMicros'], '6666666.67 rounds half up');

        $noResults = $this->seedMetaCampaign($this->account, 'Silent', ['status' => 'PAUSED']);
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $noResults->id, '2026-10-01', '2026-10-01', 5_000_000, 0);
        $paused = app(MetaAdsPerformanceTree::class)->page($this->account, $period, 'paused', 'spend', 'desc', 1);
        $this->assertNull($paused['totals']['costPerResultMicros'], 'spend with zero results has no cost per result');
    }

    public function test_the_daily_budget_total_keeps_a_stored_zero_and_is_null_only_when_no_budget_exists(): void
    {
        $period = MetaAdsPeriod::resolve('last_30', $this->account);
        $reader = app(MetaAdsPerformanceTree::class);
        $budgetOf = fn (?string $status = null) => $reader->page($this->account, $period, $status, 'spend', 'desc', 1)['totals']['dailyBudgetMinor'];

        // Absent: an active campaign with no daily budget (e.g. a lifetime budget) contributes nothing -> unknown.
        $noBudget = $this->seedMetaCampaign($this->account, 'Lifetime only', ['daily_budget_minor' => null, 'lifetime_budget_minor' => 90_000]);
        $this->assertNull($budgetOf(), 'no daily budget anywhere: null, not zero');

        // Zero: an explicitly stored zero daily budget is a real zero.
        $zero = $this->seedMetaCampaign($this->account, 'Zero budget', ['daily_budget_minor' => 0]);
        $this->assertSame(0, $budgetOf(), 'a stored zero stays zero');

        // Positive: sums active campaigns that have a daily budget.
        $this->seedMetaCampaign($this->account, 'Funded', ['daily_budget_minor' => 2_500]);
        $this->assertSame(2_500, $budgetOf());

        // Filtering: a paused budget is not counted, and the paused filter has no active budget to report.
        $this->seedMetaCampaign($this->account, 'Paused funded', ['status' => 'PAUSED', 'daily_budget_minor' => 9_000]);
        $this->assertSame(2_500, $budgetOf());
        $this->assertNull($budgetOf('paused'));

        // Another Business's / account's budgets never leak in.
        [, $otherBusiness] = $this->metaReportingTenant('Budget Other Co');
        $otherAccount = $this->metaReportingAccountFor($otherBusiness);
        $this->seedMetaCampaign($otherAccount, 'Foreign', ['daily_budget_minor' => 77_000]);
        $this->assertSame(2_500, $budgetOf());
    }
}
