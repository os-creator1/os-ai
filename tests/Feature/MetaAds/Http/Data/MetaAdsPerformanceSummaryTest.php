<?php

namespace Tests\Feature\MetaAds\Http\Data;

use App\Enums\MetaAds\MetaAdsLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/** The tree toolbar summarises the CURRENT page (campaign rows and their loaded children), and shows the all-pages total separately. */
class MetaAdsPerformanceSummaryTest extends TestCase
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

    private function summary(string $html): string
    {
        preg_match('/data-role="tree-summary">(.*?)<\/strong>/s', $html, $m);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1] ?? ''))));
    }

    public function test_the_summary_counts_the_current_page_and_names_the_overall_total(): void
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant();

        // 27 campaigns; spend rises with i, so the default spend-descending sort puts 27 first and 1, 2 on page 2.
        foreach (range(1, 27) as $i) {
            $campaign = $this->seedMetaCampaign($account, 'Camp ' . $i);
            $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', $i * 1_000_000, 1);

            if ($i === 1) {   // two ad sets, one with an ad
                $one = $this->seedMetaAdSet($campaign, 'One A');
                $this->seedMetaAdSet($campaign, 'One B');
                $this->seedMetaAd($one, 'One A ad', ['meta_ads_campaign_id' => $campaign->id]);
            }
            if ($i === 2) {   // one ad set, no ads
                $this->seedMetaAdSet($campaign, 'Two A');
            }
            if ($i === 27) {  // a child that lives on page 1 and must not be counted on page 2
                $set = $this->seedMetaAdSet($campaign, 'Top set');
                $this->seedMetaAd($set, 'Top ad', ['meta_ads_campaign_id' => $campaign->id]);
            }
        }
        $this->asMetaUser($customer);

        $page2 = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['page' => 2]))->assertOk()->getContent();
        $text = $this->summary($page2);
        $this->assertStringStartsWith('2 campaigns · 3 ad sets · 1 ads on this page', $text, 'page-local counts, not the 27 total');
        $this->assertStringContainsString('(of 27 campaigns, page 2 of 2)', $text);
        $this->assertSame(2, substr_count($page2, 'data-role="campaign-row"'));

        $page1 = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->assertOk()->getContent();
        $text1 = $this->summary($page1);
        $this->assertStringStartsWith('25 campaigns · 1 ad sets · 1 ads on this page', $text1);
        $this->assertStringContainsString('(of 27 campaigns, page 1 of 2)', $text1);
    }

    public function test_a_single_page_shows_no_overall_total_note(): void
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant();
        $this->seedMetaCampaign($account, 'Only one');
        $this->asMetaUser($customer);

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->assertOk()->getContent();

        $this->assertStringStartsWith('1 campaign · 0 ad sets · 0 ads on this page', $this->summary($html));
        $this->assertStringNotContainsString('data-role="tree-total"', $html);
    }
}
