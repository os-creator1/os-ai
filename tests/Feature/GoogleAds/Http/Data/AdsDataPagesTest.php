<?php

namespace Tests\Feature\GoogleAds\Http\Data;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\GoogleAdsKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Http\Data\Concerns\CreatesAdsDataFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — the data pages (Campaigns, Campaign detail, Keywords,
 * Search terms): they render from CACHED tables only (the fake provider's call
 * log stays empty), absent figures are a dash never 0, period / sort / filter
 * input is validated to defaults, provider strings are escaped, and every page
 * is a 404 for a Core Business, a foreign Business and an unknown campaign.
 */
class AdsDataPagesTest extends TestCase
{
    use CreatesAdsDataFixtures;
    use RefreshDatabase;

    private const PAGES = ['campaigns.index', 'keywords.index', 'search-terms.index', 'leads.index', 'recommendations.index'];

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

    /** @return array{0: string, 1: \App\Models\Workspace, 2: \App\Models\Business, 3: \App\Models\GoogleAdsAccount, 4: array<string, \App\Models\GoogleAdsCampaign>} */
    private function ready(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, array $permissions = [self::VIEW, self::MANAGE]): array
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant($tier);
        [$account, $campaigns] = $this->mirroredAdsAccount($business);
        $this->seedPhotoBoothMetrics($account);
        $this->asAdsUser($customer, $permissions);

        return ['', $workspace, $business, $account, $campaigns];
    }

    /** The first <tr data-role="$role"> whose markup contains $needle. */
    private function row(string $html, string $role, string $needle): string
    {
        preg_match_all('/<tr[^>]*data-role="' . preg_quote($role, '/') . '".*?<\/tr>/s', $html, $matches);

        foreach ($matches[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail("No [{$role}] row containing [{$needle}].");
    }

    /** The <tr data-role="$role"> whose FIRST cell is exactly $text. */
    private function rowExact(string $html, string $role, string $text): string
    {
        preg_match_all('/<tr[^>]*data-role="' . preg_quote($role, '/') . '".*?<\/tr>/s', $html, $matches);

        foreach ($matches[0] as $row) {
            if (($this->cells($row)[0] ?? null) === $text) {
                return $row;
            }
        }

        $this->fail("No [{$role}] row whose first cell is [{$text}].");
    }

    /** @return list<string> the plain text of each <td> of a row */
    private function cells(string $row): array
    {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $m);

        return array_map(fn (string $cell): string => trim(preg_replace('/\s+/', ' ', strip_tags($cell))), $m[1]);
    }

    // ---------------------------------------------------------------
    // Cached data only
    // ---------------------------------------------------------------

    public function test_every_page_renders_from_cached_tables_with_zero_provider_calls(): void
    {
        [, $workspace, $business, $account, $campaigns] = $this->ready();
        $this->seedTerm($account, 'cheap photo booth hire', 6_000_000, 6, '0');

        foreach (self::PAGES as $page) {
            foreach (['last_7', 'last_30', 'this_month', 'previous_month'] as $period) {
                $this->get($this->adsUrl($workspace, $business, $page, ['period' => $period]))->assertOk();
            }
        }

        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $rental->uid)->assertOk();

        $this->assertSame(0, $this->fakeAds->callCount(), 'a GET never calls Google');
    }

    public function test_campaigns_table_shows_the_period_figures_and_dashes_for_missing_data_never_zero(): void
    {
        [, $workspace, $business] = $this->ready();

        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.index'))->assertOk()->getContent();

        $rental = $this->cells($this->row($html, 'campaign-row', 'Photo Booth Rental'));
        $this->assertSame('Photo Booth Rental', $rental[0]);
        $this->assertStringContainsString('Enabled', $rental[1]);
        $this->assertStringContainsString('USD 4.00', $rental[2], 'the daily budget');
        $this->assertSame('USD 24.00', $rental[3]);
        $this->assertSame('40', $rental[4]);
        $this->assertSame('8', $rental[5]);
        $this->assertSame('USD 3.00', $rental[6], 'CPL = 24 / 8');
        $this->assertSame('20.0%', $rental[7]);

        // No daily rows at all: every metric is a dash, not 0 / $0.00.
        $wedding = $this->cells($this->row($html, 'campaign-row', 'Wedding Photo Booth'));
        $this->assertSame(['—', '—', '—', '—', '—'], array_slice($wedding, 3, 5));

        // Spend with ZERO conversions: a real 0 conversions, but CPL is a dash.
        $threeSixty = $this->cells($this->row($html, 'campaign-row', '360 Booth'));
        $this->assertSame('USD 16.00', $threeSixty[3]);
        $this->assertSame('0', $threeSixty[5]);
        $this->assertSame('—', $threeSixty[6]);
    }

    public function test_the_value_column_appears_only_when_a_campaign_has_conversion_value(): void
    {
        [, $workspace, $business, $account] = $this->ready();

        $this->assertStringNotContainsString('Value (Google)', $this->get($this->adsUrl($workspace, $business, 'campaigns.index'))->getContent());

        $this->seedMetric($account, GoogleAdsMetricLevel::Campaign, PhotoBoothFixture::CAMPAIGN_WEDDING, '2026-10-03', 3_000_000, 5, '1', '120.5');

        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.index'))->getContent();
        $this->assertStringContainsString('Value (Google)', $html);
        $this->assertStringContainsString('USD 120.50', $html);
    }

    public function test_period_sort_filter_and_page_input_is_validated_to_defaults_never_an_error(): void
    {
        [, $workspace, $business] = $this->ready();

        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.index', [
            'period' => 'bogus', 'sort' => 'cost_micros; DROP TABLE google_ads_campaigns', 'dir' => 'sideways',
            'status' => "x' OR 1=1 --", 'page' => '-5',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-period="last_30"', $html);
        $this->assertMatchesRegularExpression('/data-col="spend"[^>]*aria-sort="descending"|aria-sort="descending"[^>]*data-col="spend"/', $html);
        $this->assertStringContainsString('Photo Booth Rental', $html, 'an invalid status is "no filter"');

        $this->get($this->adsUrl($workspace, $business, 'campaigns.index', ['page' => '999999999999999999999', 'sort' => ['a' => 'b'], 'period' => ['x']]))->assertOk();
        $this->get($this->adsUrl($workspace, $business, 'keywords.index', ['campaign' => 'not-a-campaign', 'status' => '<script>', 'sort' => 'nope']))->assertOk();
        $this->get($this->adsUrl($workspace, $business, 'search-terms.index', ['class' => 'nonsense', 'campaign' => '1 OR 1=1', 'dir' => 'DESC;']))->assertOk();
    }

    public function test_sorting_by_name_ascending_orders_the_campaigns(): void
    {
        [, $workspace, $business] = $this->ready();

        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.index', ['sort' => 'name', 'dir' => 'asc']))->getContent();

        $this->assertLessThan(strpos($html, 'Photo Booth Rental'), strpos($html, '360 Booth'));
        $this->assertLessThan(strpos($html, 'Wedding Photo Booth'), strpos($html, 'Photo Booth Rental'));
    }

    public function test_the_status_filter_limits_the_campaigns(): void
    {
        [, $workspace, $business, , $campaigns] = $this->ready();
        $campaigns[PhotoBoothFixture::CAMPAIGN_360]->forceFill(['status' => 'PAUSED'])->save();

        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.index', ['status' => 'paused']))->getContent();

        $this->assertStringContainsString('360 Booth', $html);
        $this->assertStringNotContainsString('>Photo Booth Rental<', $html);
    }

    // ---------------------------------------------------------------
    // Campaign detail
    // ---------------------------------------------------------------

    public function test_the_campaign_detail_shows_trend_budget_ad_groups_keywords_search_terms_and_conversions(): void
    {
        [, $workspace, $business, $account, $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $this->seedTerm($account, 'cheap photo booth hire', 6_000_000, 6, '0');
        $keyword = $this->keywordNamed($account, 'photo booth rental');
        $this->seedMetric($account, GoogleAdsMetricLevel::Keyword, $keyword->external_criterion_id, '2026-10-02', 5_000_000, 5, '1');

        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $rental->uid)->assertOk()->getContent();

        $this->assertStringContainsString('data-role="chart-trend"', $html);
        $this->assertStringContainsString('data-payload=', $html);
        $this->assertStringContainsString('USD 24.00', $html);
        $this->assertStringContainsString('data-role="budget-facts"', $html);
        $this->assertStringContainsString('USD 4.00', $html, 'the daily budget');
        $this->assertStringContainsString('separate from the monthly target', $html, 'daily budget is not conflated with the monthly target');
        $this->assertStringContainsString('Photo Booth Rental - General', $html, 'ad groups');
        $this->assertStringContainsString('photo booth rental near me', $html, 'keywords');
        $this->assertStringContainsString('cheap photo booth hire', $html, 'search terms');
        $this->assertStringContainsString('data-role="conversion-data"', $html);
        $this->assertStringContainsString('USD 3.00', $html);
        $this->assertStringContainsString('Pause campaign Photo Booth Rental? It will stop showing ads in Google Ads until resumed.', preg_replace('/\s+/', ' ', strip_tags($html)));

        // No Google id anywhere in the markup.
        foreach ([PhotoBoothFixture::CAMPAIGN_RENTAL, '3000000001', PhotoBoothFixture::CUSTOMER_ID] as $external) {
            $this->assertStringNotContainsString($external, $html);
        }
    }

    public function test_an_unknown_or_foreign_campaign_uid_is_a_404(): void
    {
        [, $workspace, $business] = $this->ready();

        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/not-a-uid')->assertNotFound();

        // A campaign of ANOTHER Business, addressed through this Business's route.
        [$otherCustomer, $otherBusiness] = $this->adsHttpTenant();
        [, $otherCampaigns] = $this->mirroredAdsAccount($otherBusiness);
        $foreign = $otherCampaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];

        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $foreign->uid)->assertNotFound();
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    // ---------------------------------------------------------------
    // Keywords
    // ---------------------------------------------------------------

    public function test_keywords_show_metrics_dashes_quality_score_only_when_present_and_a_collapsed_negative_list(): void
    {
        [, $workspace, $business, $account] = $this->ready();
        $keyword = $this->keywordNamed($account, 'photo booth rental');
        $this->seedMetric($account, GoogleAdsMetricLevel::Keyword, $keyword->external_criterion_id, '2026-10-02', 5_000_000, 5, '1');

        $html = $this->get($this->adsUrl($workspace, $business, 'keywords.index'))->assertOk()->getContent();

        $withMetrics = $this->cells($this->rowExact($html, 'keyword-row', 'photo booth rental'));
        $this->assertContains('USD 5.00', $withMetrics);
        $this->assertContains('USD 5.00', $withMetrics, 'CPL = 5 / 1');
        $this->assertContains('8/10', $withMetrics, 'Quality Score shown because Google returned one');

        $noMetrics = $this->cells($this->row($html, 'keyword-row', 'wedding photo booth package'));
        $this->assertContains('—', $noMetrics);
        $this->assertNotContains('USD 0.00', $noMetrics);

        $this->assertStringContainsString('data-role="negative-keywords"', $html);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen/', substr($html, (int) strpos($html, 'data-role="negative-keywords"')), 'collapsed by default');
        $this->assertStringContainsString('diy photo booth', $html);

        GoogleAdsKeyword::query()->where('google_ads_account_id', $account->id)->update(['quality_score' => null]);
        $html = $this->get($this->adsUrl($workspace, $business, 'keywords.index'))->getContent();
        $this->assertStringNotContainsString('Quality Score', $html, 'no stored score => no column');
    }

    public function test_the_keyword_campaign_filter_only_accepts_the_accounts_own_campaigns(): void
    {
        [, $workspace, $business, , $campaigns] = $this->ready();
        $wedding = $campaigns[PhotoBoothFixture::CAMPAIGN_WEDDING];

        $html = $this->get($this->adsUrl($workspace, $business, 'keywords.index', ['campaign' => $wedding->uid]))->getContent();
        $this->assertStringContainsString('wedding photo booth package', $html);
        $this->assertStringNotContainsString('corporate photo booth', $html);

        [, $otherBusiness] = $this->adsHttpTenant();
        [, $otherCampaigns] = $this->mirroredAdsAccount($otherBusiness);
        $html = $this->get($this->adsUrl($workspace, $business, 'keywords.index', ['campaign' => $otherCampaigns[PhotoBoothFixture::CAMPAIGN_WEDDING]->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('corporate photo booth', $html, 'a foreign campaign uid is "no filter"');
    }

    // ---------------------------------------------------------------
    // Search terms
    // ---------------------------------------------------------------

    public function test_search_terms_show_states_counts_and_the_waste_summary(): void
    {
        [, $workspace, $business, $account] = $this->ready();
        $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');
        $this->seedTerm($account, 'photo booth rental near me', 21_000_000, 20, '4');
        $this->seedTerm($account, 'photo booth quote', 1_000_000, 1, '0');
        $this->seedTerm($account, 'photo booth data unknown', 9_000_000, 9, null);
        $this->seedTerm($account, 'diy photo booth', 22_000_000, 9, '0');

        $html = $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->assertOk()->getContent();

        $this->assertStringContainsString('USD 24.00 spent across 1 search term with no conversions.', preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('1 more term is already excluded', preg_replace('/\s+/', ' ', strip_tags($html)), 'an already-negated waste term is not counted');

        $this->assertStringContainsString('Potential waste', $this->row($html, 'search-term-row', 'cheap photo booth hire'));
        $this->assertStringContainsString('Converting', $this->row($html, 'search-term-row', 'photo booth rental near me'));
        $this->assertStringContainsString('Unreviewed', $this->row($html, 'search-term-row', 'photo booth quote'));
        $this->assertStringContainsString('Unreviewed', $this->row($html, 'search-term-row', 'photo booth data unknown'), 'no conversion data is never called waste');
        $this->assertStringContainsString('Already has a negative keyword', $this->row($html, 'search-term-row', '>diy photo booth<'));

        $unknown = $this->cells($this->row($html, 'search-term-row', 'photo booth data unknown'));
        $this->assertContains('—', $unknown, 'missing conversions are a dash');

        $this->assertMatchesRegularExpression('/data-class-tab="all"[^>]*>\s*All\s*<span[^>]*>5</', $html);
        $this->assertMatchesRegularExpression('/data-class-tab="potential_waste"[^>]*>\s*Potential waste\s*<span[^>]*>2</', $html);
        $this->assertMatchesRegularExpression('/data-class-tab="converting"[^>]*>\s*Converting\s*<span[^>]*>1</', $html);

        $filtered = $this->get($this->adsUrl($workspace, $business, 'search-terms.index', ['class' => 'converting']))->getContent();
        $this->assertStringContainsString('photo booth rental near me', $filtered);
        $this->assertStringNotContainsString('photo booth quote', $filtered);
    }

    public function test_the_waste_summary_is_hidden_when_there_is_none(): void
    {
        [, $workspace, $business, $account] = $this->ready();
        $this->seedTerm($account, 'photo booth rental near me', 21_000_000, 20, '4');

        $this->assertStringNotContainsString('data-role="waste-summary"', $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->getContent());
    }

    // ---------------------------------------------------------------
    // Escaping
    // ---------------------------------------------------------------

    public function test_provider_strings_are_escaped_on_every_data_page(): void
    {
        [, $workspace, $business, $account, $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $rental->forceFill(['name' => '<script>alert("c")</script>Rental'])->save();
        $keyword = $this->keywordNamed($account, 'photo booth rental');
        $keyword->forceFill(['text' => '<img src=x onerror=alert(1)>'])->save();
        $this->seedTerm($account, '<svg onload=alert(2)>', 24_000_000, 12, '0');

        foreach (['campaigns.index', 'keywords.index', 'search-terms.index', 'recommendations.index'] as $page) {
            $html = $this->get($this->adsUrl($workspace, $business, $page))->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert("c")', $html, "[{$page}] campaign name");
            $this->assertStringNotContainsString('<img src=x', $html, "[{$page}] keyword text");
            $this->assertStringNotContainsString('<svg onload', $html, "[{$page}] search term");
        }

        $detail = $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $rental->uid)->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert("c")', $detail);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;c&quot;)&lt;/script&gt;Rental', $detail);
    }

    // ---------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------

    public function test_a_core_business_gets_404_on_every_data_page(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant(WorkspacePlanTier::Core);
        [$account, $campaigns] = $this->mirroredAdsAccount($business);
        $this->asAdsUser($customer);

        foreach (self::PAGES as $page) {
            $this->get($this->adsUrl($workspace, $business, $page))->assertNotFound("[{$page}]");
        }

        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL]->uid)->assertNotFound();
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_another_customers_business_is_a_404_on_every_data_page(): void
    {
        [, $workspace, $business, , $campaigns] = $this->ready();
        [$stranger] = $this->adsHttpTenant();
        $this->asAdsUser($stranger);

        foreach (self::PAGES as $page) {
            $this->get($this->adsUrl($workspace, $business, $page))->assertNotFound("[{$page}]");
        }

        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL]->uid)->assertNotFound();
    }

    public function test_without_the_view_permission_the_data_pages_are_refused_after_tenancy(): void
    {
        [, $workspace, $business] = $this->ready(WorkspacePlanTier::Growth, [self::MANAGE]);

        foreach (self::PAGES as $page) {
            $this->get($this->adsUrl($workspace, $business, $page))->assertStatus(401);
        }
    }

    public function test_a_viewer_without_manage_sees_the_data_but_no_action_controls(): void
    {
        [, $workspace, $business, $account] = $this->ready(WorkspacePlanTier::Growth, [self::VIEW]);
        $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');

        foreach (['campaigns.index' => 'pause-campaign', 'keywords.index' => 'pause-keyword', 'search-terms.index' => 'add-negative'] as $page => $role) {
            $html = $this->get($this->adsUrl($workspace, $business, $page))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-role="' . $role . '"', $html, "[{$page}]");
        }

        $this->assertStringNotContainsString('data-role="ignore-term"', $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->getContent());
    }

    public function test_not_connected_and_no_account_states_show_the_standard_empty_state(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        foreach (self::PAGES as $page) {
            $this->get($this->adsUrl($workspace, $business, $page))->assertOk()->assertSee('data-role="ads-empty-state"', false);
        }

        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_every_page_carries_the_freshness_line(): void
    {
        [, $workspace, $business, $account] = $this->ready();
        $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');
        $rental = $this->rentalCampaign($account);

        foreach (self::PAGES as $page) {
            $this->get($this->adsUrl($workspace, $business, $page))->assertSee('data-role="ads-freshness"', false);
        }

        $this->get($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $rental->uid)->assertSee('data-role="ads-freshness"', false);
    }
}
