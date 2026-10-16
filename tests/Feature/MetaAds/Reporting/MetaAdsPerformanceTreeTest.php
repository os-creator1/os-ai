<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\Reporting\MetaAdsPerformanceTree;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertFalse($tree['totals']['partial']);
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
}
