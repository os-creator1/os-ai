<?php

namespace Tests\Feature\MetaAds\Http\Data;

use App\Library\MetaAds\Reporting\MetaAdsAdReader;
use App\Library\MetaAds\Reporting\MetaAdsAdSetReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsMetricQueries;
use App\Library\MetaAds\Reporting\MetaAdsPerformanceTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/**
 * When the child-read safeguards cut rows off, the Performance table must say so
 * at page level even though the parent already shows some children, and a
 * complete table must stay free of that warning. The safeguards are lowered
 * through the tree's constructor; their production defaults are unchanged.
 */
class MetaAdsPerformanceTruncationTest extends TestCase
{
    use CreatesMetaAdsDataFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    private function limitTree(int $adSets, int $ads): void
    {
        $this->app->bind(MetaAdsPerformanceTree::class, fn ($app) => new MetaAdsPerformanceTree(
            $app->make(MetaAdsCampaignReader::class),
            $app->make(MetaAdsAdSetReader::class),
            $app->make(MetaAdsAdReader::class),
            $app->make(MetaAdsMetricQueries::class),
            $adSets,
            $ads,
        ));
    }

    /** One campaign with three ad sets; the first ad set has three ads. @return array{0: \App\Models\Workspace, 1: \App\Models\Business} */
    private function seeded(): array
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant();
        $campaign = $this->seedMetaCampaign($account, 'Wide Campaign');

        foreach (['One', 'Two', 'Three'] as $name) {
            $set = $this->seedMetaAdSet($campaign, 'Set ' . $name);
            if ($name === 'One') {
                foreach (['A', 'B', 'C'] as $ad) {
                    $this->seedMetaAd($set, 'Ad ' . $ad, ['meta_ads_campaign_id' => $campaign->id]);
                }
            }
        }

        $this->asMetaUser($customer);

        return [$workspace, $business];
    }

    private function page($workspace, $business): string
    {
        return $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->assertOk()->getContent();
    }

    public function test_truncated_ad_sets_are_disclosed_even_though_some_are_listed(): void
    {
        [$workspace, $business] = $this->seeded();
        $this->limitTree(adSets: 2, ads: 1000);

        $html = $this->page($workspace, $business);

        $this->assertSame(2, substr_count($html, 'data-role="ad-set-row"'), 'two of three ad sets are listed');
        $this->assertSame(1, substr_count($html, 'data-role="tree-truncated"'));
        $this->assertStringContainsString('Too many ad sets to list', $html);
        $this->assertStringNotContainsString('Too many ads to list', $html);
        $this->assertStringContainsString('data-role="truncated-ad-sets-link"', $html);
        $this->assertStringContainsString('data-role="truncated-ads-link"', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.ads.meta.ad-sets.index', [$workspace->uid, $business->uid, 'period' => 'last_30']), $html);
    }

    public function test_truncated_ads_are_disclosed_even_though_some_are_listed(): void
    {
        [$workspace, $business] = $this->seeded();
        $this->limitTree(adSets: 500, ads: 2);

        $html = $this->page($workspace, $business);

        $this->assertSame(2, substr_count($html, 'data-role="ad-row"'), 'two of three ads are listed');
        $this->assertSame(1, substr_count($html, 'data-role="tree-truncated"'));
        $this->assertStringContainsString('Too many ads to list', $html);
        $this->assertStringNotContainsString('Too many ad sets to list', $html);
        $this->assertStringContainsString('data-role="truncated-ads-link"', $html);
        // An ad set that came back with no ads must not claim it has none while the ad list is cut.
        $this->assertStringNotContainsString('No ads in this ad set yet.', $html);
        $this->assertStringContainsString('Some ads are not listed here.', $html);
    }

    public function test_a_complete_table_shows_no_warning_and_keeps_truthful_empty_messages(): void
    {
        [$workspace, $business] = $this->seeded();

        $html = $this->page($workspace, $business);

        $this->assertStringNotContainsString('data-role="tree-truncated"', $html);
        $this->assertSame(3, substr_count($html, 'data-role="ad-set-row"'));
        $this->assertSame(3, substr_count($html, 'data-role="ad-row"'));
        $this->assertStringContainsString('No ads in this ad set yet.', $html, 'a genuinely empty ad set still says so');
    }
}
