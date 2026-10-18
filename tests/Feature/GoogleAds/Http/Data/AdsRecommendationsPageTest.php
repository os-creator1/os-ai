<?php

namespace Tests\Feature\GoogleAds\Http\Data;

use App\Library\GoogleAds\PhotoBoothFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Http\Data\Concerns\CreatesAdsDataFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §12 — the Recommendations page is a read-only,
 * calm presentation of deterministic facts: problem, evidence, factual basis,
 * and a link to the owning flow. No dismiss / snooze / apply lifecycle, no red
 * alert styling, and absent evidence means no card at all.
 */
class AdsRecommendationsPageTest extends TestCase
{
    use CreatesAdsDataFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdsHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    private function page(array $accountOverrides = [], ?callable $seed = null, array $query = []): string
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        [$account, $campaigns] = $this->mirroredAdsAccount($business, $accountOverrides);
        $seed && $seed($account, $campaigns);
        $this->asAdsUser($customer);
        $this->workspace = $workspace;
        $this->business = $business;

        return $this->get($this->adsUrl($workspace, $business, 'recommendations.index', $query))->assertOk()->getContent();
    }

    private $workspace;

    private $business;

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    public function test_facts_are_shown_as_cards_with_evidence_basis_and_a_valid_link(): void
    {
        $html = $this->page([], function ($account) {
            $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_360, '2026-10-01', '2026-10-04', 20_000_000, 10, '0');
            $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_RENTAL, '2026-10-01', '2026-10-04', 6_000_000, 10, '2');
            $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');
        });

        $text = $this->text($html);
        $this->assertStringContainsString('Potential wasted spend on search terms', $text);
        $this->assertStringContainsString('USD 24.00 spent on 1 search term with no conversions', $text);
        $this->assertStringContainsString('360 Booth has spend but no conversions yet', $text);
        $this->assertStringContainsString('Based on your cached Google Ads data for the last 30 days; deterministic rule.', $text);
        $this->assertStringContainsString('Review search terms', $text);
        $this->assertStringContainsString('Review campaign', $text);

        // Links go to the page that owns the action, and every one of them opens.
        preg_match_all('/<a href="([^"]+)" class="btn btn-sm btn-outline-secondary" data-role="recommendation-action"/', $html, $links);
        $this->assertCount(2, $links[1]);
        $this->assertContains($this->adsUrl($this->workspace, $this->business, 'search-terms.index', ['class' => 'potential_waste', 'period' => 'last_30']), array_map('html_entity_decode', $links[1]));

        foreach ($links[1] as $href) {
            $this->get(html_entity_decode($href))->assertOk();
        }

        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_page_has_no_lifecycle_controls_and_no_alarm_styling(): void
    {
        $html = $this->page([], function ($account) {
            $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_360, '2026-10-01', '2026-10-04', 20_000_000, 10, '0');
            $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');
        });

        $start = (int) strpos($html, 'data-role="ads-title"');
        $region = substr($html, $start, (int) strpos($html, 'data-role="recommendations-note"') - $start);

        foreach (['Dismiss', 'Snooze', 'dismiss', 'snooze', 'Apply', '<form method="POST"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $region, "[{$forbidden}] belongs to the Opportunity Engine, not this page");
        }

        foreach (['bg-light-danger', 'alert-danger', 'text-danger', 'border-danger', 'btn-danger'] as $alarm) {
            $this->assertStringNotContainsString($alarm, $region, "[{$alarm}] ordinary optimisation is never styled as an alarm");
        }

        $this->assertMatchesRegularExpression('/data-role="recommendation"[^>]*data-tone="warning"/', $html);
    }

    public function test_an_absent_or_weak_signal_shows_nothing_to_flag(): void
    {
        $html = $this->page([], function ($account) {
            $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_RENTAL, '2026-10-01', '2026-10-04', 3_000_000, 4, '1');
            // A term with NO conversion data is never "waste".
            $this->seedTerm($account, 'photo booth mystery', 30_000_000, 30, null);
        });

        $this->assertStringContainsString('data-role="no-recommendations"', $html);
        $this->assertStringContainsString('Nothing to flag right now', $html);
        $this->assertStringContainsString('enough spend and conversion data', $this->text($html));
        $this->assertStringNotContainsString('data-role="recommendation"', $html);
    }

    public function test_pacing_facts_link_to_the_budget_page_and_name_this_month(): void
    {
        $this->pinAdsClock('2026-10-14 12:00:00');

        $html = $this->page(['monthly_budget_target_micros' => 100_000_000], function ($account) {
            $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_RENTAL, '2026-10-01', '2026-10-14', 8_000_000, 10, '2');
        });

        $text = $this->text($html);
        $this->assertStringContainsString('Spending is running ahead of your monthly budget', $text);
        $this->assertStringContainsString('Based on your cached Google Ads data for this month; deterministic rule.', $text);
        $this->assertStringContainsString('Review budget', $text);
        $this->assertStringContainsString('href="' . $this->adsUrl($this->workspace, $this->business, 'budget') . '"', $html);
    }

    public function test_the_period_selector_changes_the_basis_wording_without_calling_google(): void
    {
        $html = $this->page([], function ($account) {
            $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');
        }, ['period' => 'last_7']);

        $this->assertStringContainsString('data-role="period-selector"', $html);
        $this->assertStringContainsString('for the last 7 days; deterministic rule', $this->text($html));
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_a_campaign_card_never_shows_a_google_id(): void
    {
        $html = $this->page([], function ($account) {
            $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_360, '2026-10-01', '2026-10-04', 20_000_000, 10, '0');
        });

        $this->assertStringContainsString('360 Booth', $html);
        $this->assertStringNotContainsString(PhotoBoothFixture::CAMPAIGN_360, $html);
    }
}
