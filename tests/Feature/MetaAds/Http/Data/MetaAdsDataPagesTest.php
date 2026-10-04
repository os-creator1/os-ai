<?php

namespace Tests\Feature\MetaAds\Http\Data;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaAdsLevel;
use App\Enums\MetaAds\MetaConnectionState;
use App\Library\MetaAds\MetaAdsMoney;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 — the Campaigns, Campaign detail, Ad sets and Ads pages:
 * states, whitelisting of every query parameter, pagination, escaping of
 * provider strings, thumbnail allow-list, tenancy / entitlement / permission
 * order, and the guarantee that a GET never calls Meta. FAKE provider only.
 */
class MetaAdsDataPagesTest extends TestCase
{
    use CreatesMetaAdsDataFixtures;
    use RefreshDatabase;

    private const XSS = '<script>alert(1)</script>';

    private const ALLOWED_THUMB = 'https://scontent.xx.fbcdn.net/v/t45/allowed-thumb.jpg';

    private const BLOCKED_THUMB = 'https://evil.example.com/tracker/blocked-thumb.jpg';

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

    /**
     * @return array{0: \App\Models\Workspace, 1: \App\Models\Business, 2: \App\Models\MetaAdsAccount, 3: array<string, mixed>}
     */
    private function ready(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, array $permissions = [self::VIEW, self::MANAGE], array $accountOverrides = []): array
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant($tier, $accountOverrides);
        $rows = $this->seedPages($account);
        $this->asMetaUser($customer, $permissions);

        return [$workspace, $business, $account, $rows];
    }

    /** @return array<string, mixed> */
    private function seedPages(\App\Models\MetaAdsAccount $account): array
    {
        $alpha = $this->seedMetaCampaign($account, 'Alpha ' . self::XSS, ['daily_budget_minor' => 4000]);
        $bravo = $this->seedMetaCampaign($account, 'Bravo', ['status' => 'PAUSED', 'effective_status' => 'PAUSED', 'daily_budget_minor' => null, 'lifetime_budget_minor' => 90000]);
        $charlie = $this->seedMetaCampaign($account, 'Charlie Quiet');

        $alphaSet = $this->seedMetaAdSet($alpha, 'Alpha Set ' . self::XSS, [
            'targeting_summary' => 'Ages 25-44 <img src=x onerror=1>',
            'daily_budget_minor' => 2000,
            'frequency_7d' => '2.5000',
            'reach_7d' => 1200,
        ]);
        $bravoSet = $this->seedMetaAdSet($bravo, 'Bravo Set', ['status' => 'PAUSED', 'effective_status' => 'CAMPAIGN_PAUSED']);

        $adOk = $this->seedMetaAd($alphaSet, 'Carousel ad', [
            'meta_ads_campaign_id' => $alpha->id,
            'creative_title' => 'Title ' . self::XSS,
            'creative_body' => 'Body <b>bold</b>',
            'creative_thumbnail_url' => self::ALLOWED_THUMB,
        ]);
        $adBlocked = $this->seedMetaAd($alphaSet, 'Blocked thumb ad', [
            'creative_thumbnail_url' => self::BLOCKED_THUMB,
        ]);
        $adIssue = $this->seedMetaAd($bravoSet, 'Issue ad', ['effective_status' => 'WITH_ISSUES']);

        $this->seedMetaDays($account, MetaAdsLevel::Campaign, $alpha->id, '2026-10-01', '2026-10-03', 10_000_000, 1, 10, 1000, 20, '5');
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, $bravo->id, '2026-10-01', '2026-10-03', 20_000_000, null);
        $this->seedMetaResult($account, MetaAdsLevel::Campaign, $bravo->id, '2026-10-02', 2);
        $this->seedMetaDays($account, MetaAdsLevel::AdSet, $alphaSet->id, '2026-10-01', '2026-10-03', 8_000_000, 1);
        $this->seedMetaDays($account, MetaAdsLevel::Ad, $adOk->id, '2026-10-01', '2026-10-03', 4_000_000, 1);

        return compact('alpha', 'bravo', 'charlie', 'alphaSet', 'bravoSet', 'adOk', 'adBlocked', 'adIssue');
    }

    /** @return array<string, string> page name => url */
    private function allPages(\App\Models\Workspace $workspace, \App\Models\Business $business, array $rows): array
    {
        return [
            'campaigns' => $this->metaUrl($workspace, $business, 'campaigns.index'),
            'campaign' => $this->metaCampaignUrl($workspace, $business, $rows['alpha']->uid),
            'ad-sets' => $this->metaUrl($workspace, $business, 'ad-sets.index'),
            'ads' => $this->metaUrl($workspace, $business, 'ads.index'),
        ];
    }

    // ---------------------------------------------------------------
    // Campaigns
    // ---------------------------------------------------------------

    public function test_campaigns_page_shows_status_budget_and_typed_figures(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $response = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('data-role="campaigns-table"', $html);
        $this->assertStringContainsString('data-provider="meta"', $html, 'the shared header marks Meta as the active provider');
        $this->assertStringContainsString(MetaAdsMoney::format(30_000_000, 'USD'), $html, 'Alpha spend');
        $this->assertStringContainsString(MetaAdsMoney::format(60_000_000, 'USD'), $html, 'Bravo spend');
        $this->assertStringContainsString(MetaAdsMoney::format(10_000_000, 'USD'), $html, 'Alpha cost per result (3 results)');
        $this->assertStringContainsString('/ day', $html, 'a daily budget is labelled');
        $this->assertStringContainsString('lifetime', $html, 'a lifetime budget is labelled');
        $this->assertStringContainsString('Results count: Leads (on-Facebook forms)', $html);
        $this->assertStringContainsString('Campaign', $html);
        $this->assertStringContainsString($rows['alpha']->uid, $html, 'rows are addressed by uid');
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('Paused', $html);
    }

    public function test_campaign_without_insight_rows_shows_dashes_never_zero(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['sort' => 'name', 'dir' => 'asc']))->getContent();

        $charlie = $this->rowHtml($html, 'Charlie Quiet');
        $this->assertStringContainsString('—', $charlie);
        $this->assertStringNotContainsString(MetaAdsMoney::format(0, 'USD'), $charlie);
        $this->assertDoesNotMatchRegularExpression('/<td class="text-numeric text-end">\s*0\s*<\/td>/', $charlie);
    }

    private function rowHtml(string $html, string $needle): string
    {
        preg_match_all('/<tr data-role="[a-z-]+-row">.*?<\/tr>/s', $html, $matches);

        foreach ($matches[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail('No table row contains [' . $needle . ']');
    }

    public function test_result_type_unset_shows_the_hint_and_no_result_figures(): void
    {
        [$workspace, $business] = $this->ready(accountOverrides: ['result_action_type' => null]);

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent();

        $this->assertStringContainsString('data-role="result-type-unset"', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.ads.meta.settings', [$workspace->uid, $business->uid]), $html);
        $this->assertStringNotContainsString('data-role="result-type-note"', $html);
        // Spend is still known; cost per result is not (so Alpha's $10.00 cost per result is not shown).
        $this->assertStringContainsString(MetaAdsMoney::format(30_000_000, 'USD'), $this->rowHtml($html, 'Alpha'));
        $this->assertStringNotContainsString(MetaAdsMoney::format(10_000_000, 'USD'), $this->rowHtml($html, 'Alpha'));
    }

    public function test_status_filter_and_sort_apply(): void
    {
        [$workspace, $business] = $this->ready();

        $active = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['status' => 'active']))->getContent();
        $this->assertStringContainsString('Alpha', $active);
        $this->assertStringNotContainsString('Bravo', $active);

        $paused = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['status' => 'paused']))->getContent();
        $this->assertStringContainsString('Bravo', $paused);
        $this->assertStringNotContainsString('Charlie', $paused);

        $sorted = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['sort' => 'name', 'dir' => 'asc']))->getContent();
        $this->assertStringContainsString('aria-sort="ascending"', $sorted);
        $this->assertLessThan(strpos($sorted, 'Bravo'), strpos($sorted, 'Alpha &lt;script'), 'Alpha sorts before Bravo');
    }

    public function test_hostile_query_strings_fall_back_to_defaults_and_never_error(): void
    {
        [$workspace, $business] = $this->ready();
        $base = $this->metaUrl($workspace, $business, 'campaigns.index');

        $hostile = [
            '?sort=name%3BDROP%20TABLE%20meta_ads_campaigns&dir=sideways&status=%27%20OR%201%3D1--&period=forever&page=-5',
            '?sort[]=spend&dir[]=asc&status[]=active&period[]=last_7&page[]=2&campaign[]=x',
            '?sort=' . str_repeat('a', 5000) . '&page=99999999999999999999',
            '?status=archived&campaign=' . $this->hostileUid(),
        ];

        foreach ($hostile as $query) {
            $this->get($base . $query)->assertOk();
        }

        $html = $this->get($base . '?sort=bogus&dir=up&period=never')->getContent();
        $this->assertStringContainsString('data-period="last_30"', $html);
        $this->assertMatchesRegularExpression('/data-period="last_30"[^>]*>/', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
        // The default sort (spend, highest first) is what is shown: Bravo (60) before Alpha (30).
        $this->assertLessThan(strpos($html, 'Alpha &lt;script'), strpos($html, 'Bravo'));
    }

    private function hostileUid(): string
    {
        return rawurlencode("' OR 1=1 --<script>");
    }

    public function test_the_period_selector_changes_the_range_and_keeps_filters(): void
    {
        [$workspace, $business] = $this->ready();

        $response = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['period' => 'last_7', 'status' => 'paused']));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('data-period="last_7"', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*status=paused[^"]*period=last_30[^"]*"/', html_entity_decode($html));
        // The last 7 days still hold the October rows.
        $this->assertStringContainsString('Bravo', $html);
    }

    public function test_pagination_pages_through_campaigns_and_keeps_the_filter(): void
    {
        [$workspace, $business, $account] = $this->ready();

        for ($i = 1; $i <= 30; $i++) {
            $this->seedMetaCampaign($account, sprintf('Bulk %02d', $i), ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        }

        $first = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['status' => 'paused', 'sort' => 'name', 'dir' => 'asc']));
        $first->assertOk();
        $this->assertStringContainsString('Page 1 of 2', $first->getContent());
        $this->assertStringContainsString('rel="next"', $first->getContent());
        $this->assertStringNotContainsString('rel="prev"', $first->getContent());
        $this->assertStringContainsString('page=2', html_entity_decode($first->getContent()));
        $this->assertStringContainsString('status=paused', html_entity_decode($first->getContent()));

        $second = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['status' => 'paused', 'sort' => 'name', 'dir' => 'asc', 'page' => 2]));
        $this->assertStringContainsString('Page 2 of 2', $second->getContent());
        $this->assertStringContainsString('rel="prev"', $second->getContent());
        $this->assertStringContainsString('Bulk 30', $second->getContent());

        // A page far beyond the end is clamped, never an error.
        $this->get($this->metaUrl($workspace, $business, 'campaigns.index', ['page' => 500]))->assertOk();
    }

    public function test_empty_account_shows_the_no_campaigns_message(): void
    {
        [$customer, $business, $workspace] = $this->dataTenant();
        $this->asMetaUser($customer);

        $response = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'));

        $response->assertOk();
        $this->assertStringContainsString('data-role="no-campaigns"', $response->getContent());
        $this->assertStringContainsString('No campaigns to show', $response->getContent());
    }

    // ---------------------------------------------------------------
    // Campaign detail
    // ---------------------------------------------------------------

    public function test_campaign_detail_shows_kpis_trend_budget_and_children(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $response = $this->get($this->metaCampaignUrl($workspace, $business, $rows['alpha']->uid));

        $response->assertOk();
        $html = $response->getContent();

        foreach (['kpi-spend', 'kpi-results', 'kpi-cpr', 'kpi-link-clicks', 'trend-card', 'budget-facts', 'campaign-ad-sets', 'campaign-ads'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, $role);
        }

        $this->assertStringContainsString(MetaAdsMoney::format(30_000_000, 'USD'), $html);
        $this->assertStringContainsString('Show daily figures', $html, 'no-JavaScript fallback table');

        preg_match('/data-role="chart-trend" data-payload="([^"]*)"/', $html, $m);
        $payload = json_decode(html_entity_decode($m[1]), true);
        $this->assertIsArray($payload);
        $this->assertSame('USD', $payload['currency']);
        $this->assertArrayHasKey('spend', $payload['series']);
        $this->assertArrayHasKey('results', $payload['series']);

        // Budget: the campaign's own daily budget, explicitly separate from the Business monthly target.
        $this->assertStringContainsString(MetaAdsMoney::format(40_000_000, 'USD'), $html);
        $this->assertStringContainsString('separate from the monthly target', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.ads.meta.settings', [$workspace->uid, $business->uid]), $html);

        // Children and the lists they link to.
        $this->assertStringContainsString('data-role="ad-set-row"', $html);
        $this->assertStringContainsString('data-role="ad-row"', $html);
        $this->assertStringContainsString('Frequency (last 7 days)', $html);
        $this->assertStringContainsString('campaign=' . $rows['alpha']->uid, html_entity_decode($html));
    }

    public function test_meta_reported_value_only_shows_when_positive(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $alpha = $this->get($this->metaCampaignUrl($workspace, $business, $rows['alpha']->uid))->getContent();
        $this->assertStringContainsString('data-role="meta-reported-value"', $alpha);
        $this->assertStringContainsString(MetaAdsMoney::format(15_000_000, 'USD'), $alpha, '3 days x 5 = 15');
        $this->assertStringContainsString('Meta-reported value', $alpha);

        $bravo = $this->get($this->metaCampaignUrl($workspace, $business, $rows['bravo']->uid))->getContent();
        $this->assertStringNotContainsString('data-role="meta-reported-value"', $bravo);
        $this->assertStringContainsString('lifetime budget', strtolower($bravo));
    }

    public function test_campaign_detail_for_a_quiet_campaign_explains_there_is_no_data(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $html = $this->get($this->metaCampaignUrl($workspace, $business, $rows['charlie']->uid))->getContent();

        $this->assertStringContainsString('data-role="no-period-data"', $html);
        $this->assertStringContainsString('data-role="no-ad-sets"', $html);
        $this->assertStringContainsString('data-role="no-ads"', $html);
    }

    public function test_unknown_foreign_or_malformed_campaign_uids_are_404(): void
    {
        [$workspace, $business] = $this->ready();
        [, $otherBusiness, , $otherAccount] = $this->dataTenant();
        $foreign = $this->seedMetaCampaign($otherAccount, 'Foreign Campaign');

        $this->get($this->metaCampaignUrl($workspace, $business, '00000000-0000-4000-8000-000000000000'))->assertNotFound();
        $this->get($this->metaCampaignUrl($workspace, $business, $foreign->uid))->assertNotFound();
        $this->get($this->metaCampaignUrl($workspace, $business, 'not-a-uid'))->assertNotFound();
        $this->get($this->metaCampaignUrl($workspace, $business, $foreign->external_campaign_id))->assertNotFound();
        $this->get($this->metaCampaignUrl($workspace, $business, rawurlencode("' OR 1=1 --")))->assertNotFound();
        $this->get($this->metaCampaignUrl($workspace, $business, (string) MetaAdsCampaign::query()->where('name', 'Bravo')->value('id')))->assertNotFound();
    }

    public function test_campaign_detail_with_no_selected_account_is_a_404(): void
    {
        [$customer, $business, $workspace] = $this->dataTenant(withAccount: false);
        $this->asMetaUser($customer);

        $this->get($this->metaCampaignUrl($workspace, $business, '00000000-0000-4000-8000-000000000000'))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Ad sets
    // ---------------------------------------------------------------

    public function test_ad_sets_page_shows_audience_summary_and_labelled_frequency(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $response = $this->get($this->metaUrl($workspace, $business, 'ad-sets.index'));

        $response->assertOk();
        $html = $response->getContent();
        $alpha = $this->rowHtml($html, 'Alpha Set');
        $this->assertStringContainsString('Ages 25-44 &lt;img src=x onerror=1&gt;', $alpha, 'audience summary is escaped customer text');
        $this->assertStringContainsString('data-role="ad-set-frequency">2.5<', $alpha);
        $this->assertStringContainsString('Frequency (last 7 days)', $html);
        $this->assertStringContainsString(MetaAdsMoney::format(24_000_000, 'USD'), $alpha, '3 days x 8 = 24 spend');
        $this->assertStringContainsString(MetaAdsMoney::format(20_000_000, 'USD'), $alpha, 'daily budget 2000 minor');
        $this->assertStringContainsString('Campaign', $html);
        $this->assertStringContainsString($rows['alpha']->uid, $alpha, 'links to its campaign by uid');

        $bravo = $this->rowHtml($html, 'Bravo Set');
        $this->assertStringContainsString('data-role="ad-set-frequency">—<', $bravo);
        $this->assertStringContainsString('Paused (campaign paused)', $bravo);
    }

    public function test_ad_sets_filter_by_campaign_and_status_and_ignore_a_foreign_campaign(): void
    {
        [$workspace, $business, , $rows] = $this->ready();
        [, , , $otherAccount] = $this->dataTenant();
        $foreign = $this->seedMetaCampaign($otherAccount, 'Foreign Campaign');

        $bravoOnly = $this->get($this->metaUrl($workspace, $business, 'ad-sets.index', ['campaign' => $rows['bravo']->uid]))->getContent();
        $this->assertStringContainsString('Bravo Set', $bravoOnly);
        $this->assertStringNotContainsString('Alpha Set', $bravoOnly);

        $paused = $this->get($this->metaUrl($workspace, $business, 'ad-sets.index', ['status' => 'paused']))->getContent();
        $this->assertStringContainsString('Bravo Set', $paused);
        $this->assertStringNotContainsString('Alpha Set', $paused);

        // A foreign / unknown campaign uid is "no filter", not a leak and not an error.
        foreach ([$foreign->uid, 'garbage', $this->hostileUid()] as $uid) {
            $all = $this->get($this->metaUrl($workspace, $business, 'ad-sets.index', ['campaign' => $uid]));
            $all->assertOk();
            $this->assertStringContainsString('Alpha Set', $all->getContent());
            $this->assertStringContainsString('Bravo Set', $all->getContent());
            $this->assertStringNotContainsString('Foreign Campaign', $all->getContent());
        }
    }

    public function test_ad_sets_sort_whitelist_accepts_frequency_and_rejects_the_rest(): void
    {
        [$workspace, $business] = $this->ready();

        $this->get($this->metaUrl($workspace, $business, 'ad-sets.index', ['sort' => 'frequency', 'dir' => 'desc']))
            ->assertOk()->assertSee('aria-sort="descending"', false);
        $this->get($this->metaUrl($workspace, $business, 'ad-sets.index', ['sort' => 'targeting_summary; DROP', 'dir' => 'x']))->assertOk();
    }

    // ---------------------------------------------------------------
    // Ads
    // ---------------------------------------------------------------

    public function test_ads_page_renders_creative_previews_with_the_thumbnail_allow_list(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $response = $this->get($this->metaUrl($workspace, $business, 'ads.index'));

        $response->assertOk();
        $html = $response->getContent();
        $ok = $this->rowHtml($html, 'Carousel ad');

        $this->assertStringContainsString('src="' . self::ALLOWED_THUMB . '"', $ok);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $ok);
        $this->assertStringContainsString('loading="lazy"', $ok);
        $this->assertMatchesRegularExpression('/<img [^>]*alt="[^"]+"/', $ok);
        $this->assertStringNotContainsString('evil.example.com', $html, 'a non-allow-listed thumbnail host is never rendered');
        $this->assertStringNotContainsString('data-role="creative-thumbnail"', $this->rowHtml($html, 'Blocked thumb ad'));

        // Raw customer creative text is escaped.
        $this->assertStringNotContainsString(self::XSS, $html);
        $this->assertStringContainsString('Title &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Body &lt;b&gt;bold&lt;/b&gt;', $html);

        // Ad set / campaign context and the figures.
        $this->assertStringContainsString('Alpha Set', $ok);
        $this->assertStringContainsString(MetaAdsMoney::format(12_000_000, 'USD'), $ok, '3 days x 4 = 12 spend');
        $this->assertStringContainsString($rows['alpha']->uid, $ok);
    }

    public function test_an_ad_with_a_provider_issue_quotes_metas_wording(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'ads.index'))->getContent();

        $this->assertStringContainsString('Meta reports: with issues', $this->rowHtml($html, 'Issue ad'));
        $this->assertStringNotContainsString('Meta reports', $this->rowHtml($html, 'Carousel ad'));
    }

    public function test_ads_filters_by_campaign_ad_set_and_status(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $byCampaign = $this->get($this->metaUrl($workspace, $business, 'ads.index', ['campaign' => $rows['bravo']->uid]))->getContent();
        $this->assertStringContainsString('Issue ad', $byCampaign);
        $this->assertStringNotContainsString('Carousel ad', $byCampaign);

        $byAdSet = $this->get($this->metaUrl($workspace, $business, 'ads.index', ['ad_set' => $rows['alphaSet']->uid]))->getContent();
        $this->assertStringContainsString('Carousel ad', $byAdSet);
        $this->assertStringContainsString('Blocked thumb ad', $byAdSet);
        $this->assertStringNotContainsString('Issue ad', $byAdSet);

        $paused = $this->get($this->metaUrl($workspace, $business, 'ads.index', ['status' => 'paused']))->getContent();
        $this->assertStringContainsString('No ads match these filters', $paused);

        $this->get($this->metaUrl($workspace, $business, 'ads.index', ['sort' => 'ad_set', 'dir' => 'asc']))->assertOk();
        $this->get($this->metaUrl($workspace, $business, 'ads.index', ['ad_set' => 'nope', 'campaign' => ['x'], 'sort' => 'creative_title']))->assertOk();
    }

    // ---------------------------------------------------------------
    // Cross-cutting: escaping, id leakage, no provider call
    // ---------------------------------------------------------------

    public function test_provider_strings_are_escaped_on_every_page(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        foreach ($this->allPages($workspace, $business, $rows) as $name => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::XSS, $html, "[{$name}] raw script tag");
            $this->assertStringNotContainsString('<img src=x onerror=1>', $html, "[{$name}] raw img");
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, "[{$name}] escaped script text");
        }
    }

    public function test_no_provider_id_appears_in_any_page_markup_or_url(): void
    {
        [$workspace, $business, $account, $rows] = $this->ready();

        $ids = [];
        foreach ([MetaAdsCampaign::class => 'external_campaign_id', MetaAdsAdSet::class => 'external_ad_set_id', MetaAdsAd::class => 'external_ad_id'] as $model => $column) {
            $ids = array_merge($ids, $model::query()->pluck($column)->all());
        }
        $ids[] = (string) $account->ad_account_id;
        $ids[] = 'act_' . $account->ad_account_id;
        $ids[] = MetaPhotoBoothFixture::META_USER_ID;

        $pages = $this->allPages($workspace, $business, $rows) + ['recommendations' => $this->metaUrl($workspace, $business, 'recommendations.index')];

        foreach ($pages as $name => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach ($ids as $id) {
                $this->assertStringNotContainsString((string) $id, $html, "[{$name}] leaks a provider id");
            }

            $this->assertStringNotContainsString('plain-meta-access-token', $html);
            $this->assertStringNotContainsString('access_token', $html);
        }
    }

    public function test_a_get_never_calls_the_provider(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $pages = $this->allPages($workspace, $business, $rows) + ['recommendations' => $this->metaUrl($workspace, $business, 'recommendations.index')];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        $this->assertSame(0, $this->fakeMeta->callCount(), 'the data pages read cached rows only');
    }

    // ---------------------------------------------------------------
    // Actions column and confirmation dialogs
    // ---------------------------------------------------------------

    public function test_action_column_and_dialogs_state_exactly_what_changes(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent();

        $this->assertStringContainsString('data-role="pause-campaign"', $html);
        $this->assertStringContainsString('data-role="resume-campaign"', $html);
        $this->assertStringContainsString('Pause campaign Alpha &lt;script&gt;alert(1)&lt;/script&gt;?', $html);
        $this->assertStringContainsString('It and its ad sets stop delivering until resumed.', $html);
        $this->assertStringContainsString('Resume campaign Bravo?', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.ads.meta.campaigns.pause', [$workspace->uid, $business->uid, $rows['alpha']->uid]), $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="from" value="campaigns"', $html);

        $adSets = $this->get($this->metaUrl($workspace, $business, 'ad-sets.index'))->getContent();
        $this->assertStringContainsString('It and its ads stop delivering until resumed.', $adSets);
        $this->assertStringContainsString('data-role="pause-ad-set"', $adSets);

        $ads = $this->get($this->metaUrl($workspace, $business, 'ads.index'))->getContent();
        $this->assertStringContainsString('It stops delivering until resumed.', $ads);
        $this->assertStringContainsString('data-role="pause-ad"', $ads);
    }

    public function test_view_only_users_see_no_action_column_or_dialogs(): void
    {
        [$workspace, $business] = $this->ready(permissions: [self::VIEW]);

        foreach (['campaigns.index', 'ad-sets.index', 'ads.index'] as $name) {
            $html = $this->get($this->metaUrl($workspace, $business, $name))->assertOk()->getContent();

            $this->assertStringNotContainsString('data-role="confirm-dialog"', $html, $name);
            $this->assertStringNotContainsString('data-role="pause-', $html, $name);
            $this->assertStringNotContainsString('data-role="resume-', $html, $name);
            $this->assertStringNotContainsString('data-col="action"', $html, $name);
        }
    }

    public function test_a_read_only_connection_hides_actions_and_says_to_reconnect(): void
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant(connectionOverrides: ['granted_scopes' => 'ads_read']);
        $this->seedPages($account);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="pause-campaign"', $html);
        $this->assertStringContainsString('Reconnect Meta to allow pause/resume.', $html);
    }

    public function test_deleted_and_archived_rows_offer_no_action(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $this->seedMetaCampaign($account, 'Gone Campaign', ['status' => 'DELETED', 'effective_status' => 'DELETED']);

        $row = $this->rowHtml($this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent(), 'Gone Campaign');

        $this->assertStringNotContainsString('data-role="pause-campaign"', $row);
        $this->assertStringNotContainsString('data-role="resume-campaign"', $row);
        $this->assertStringContainsString('Deleted', $row);
    }

    // ---------------------------------------------------------------
    // States
    // ---------------------------------------------------------------

    public function test_not_connected_expired_and_no_account_states_render_the_standard_empty_state(): void
    {
        // not connected
        [$customer, $business, $workspace] = $this->dataTenant(withAccount: false);
        $this->asMetaUser($customer);

        foreach (['campaigns.index', 'ad-sets.index', 'ads.index', 'recommendations.index'] as $name) {
            $response = $this->get($this->metaUrl($workspace, $business, $name));
            $response->assertOk()->assertSee('data-state="not_connected"', false);
            $this->assertStringNotContainsString('data-role="campaigns-table"', $response->getContent());
        }

        // connected, no account chosen
        [$customer2, $business2, $workspace2] = $this->dataTenant(withAccount: false);
        $this->activeMetaConnection($business2);
        $this->asMetaUser($customer2);
        $this->get($this->metaUrl($workspace2, $business2, 'campaigns.index'))->assertOk()->assertSee('data-state="no_account"', false);

        // expired token
        [$customer3, $business3, $workspace3] = $this->dataTenant(withAccount: false);
        $this->activeMetaConnection($business3, ['state' => MetaConnectionState::Expired, 'access_token_encrypted' => null]);
        $this->asMetaUser($customer3);
        $this->get($this->metaUrl($workspace3, $business3, 'ads.index'))->assertOk()->assertSee('data-state="expired"', false);

        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_a_disconnected_selection_shows_no_figures(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $account->forceFill(['selected_at' => null])->save();

        $response = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'));

        $response->assertOk()->assertSee('data-state="no_account"', false);
        $this->assertStringNotContainsString('Bravo', $response->getContent());
    }

    // ---------------------------------------------------------------
    // Tenancy, entitlement, permission
    // ---------------------------------------------------------------

    public function test_core_businesses_get_a_404_on_every_data_page(): void
    {
        [$workspace, $business, , $rows] = $this->ready(WorkspacePlanTier::Core);

        foreach ($this->allPages($workspace, $business, $rows) + ['recommendations' => $this->metaUrl($workspace, $business, 'recommendations.index')] as $name => $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_without_view_permission_every_data_page_is_401_after_tenancy(): void
    {
        [$workspace, $business, , $rows] = $this->ready(permissions: []);

        foreach ($this->allPages($workspace, $business, $rows) + ['recommendations' => $this->metaUrl($workspace, $business, 'recommendations.index')] as $name => $url) {
            $this->get($url)->assertStatus(401);
        }

        // Another Business's route stays a 404 (tenancy before permission).
        [, $otherBusiness, $otherWorkspace] = $this->dataTenant();
        $this->get($this->metaUrl($otherWorkspace, $otherBusiness, 'campaigns.index'))->assertNotFound();
    }

    public function test_another_customers_business_is_a_404_on_every_data_page(): void
    {
        [$workspace, $business, , $rows] = $this->ready();
        [$stranger] = $this->dataTenant();
        $this->asMetaUser($stranger);

        foreach ($this->allPages($workspace, $business, $rows) + ['recommendations' => $this->metaUrl($workspace, $business, 'recommendations.index')] as $name => $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_an_unentitled_business_without_a_plan_is_a_404(): void
    {
        [$customer, $business, $workspace] = $this->dataTenant(null, withAccount: false);
        $this->asMetaUser($customer);

        $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->assertNotFound();
    }

    public function test_the_connection_row_of_another_business_never_leaks_into_a_page(): void
    {
        [$workspace, $business] = $this->ready();
        [, $otherBusiness, , $otherAccount] = $this->dataTenant();
        $this->seedMetaCampaign($otherAccount, 'Other Business Campaign');

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent();

        $this->assertStringNotContainsString('Other Business Campaign', $html);
        $this->assertSame(2, BusinessMetaConnection::query()->count());
    }
}
