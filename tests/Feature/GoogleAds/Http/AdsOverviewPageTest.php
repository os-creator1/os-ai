<?php

namespace Tests\Feature\GoogleAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleAds\Http\Concerns\CreatesAdsHttpFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — the Overview page: every state in contract 23 §16,
 * the KPI/figure rules of §9 (absent is a dash, never 0), freshness, the
 * period selector and the series endpoint. CACHED DATA ONLY: the fake
 * provider's call log must stay empty for every page and every period.
 */
class AdsOverviewPageTest extends TestCase
{
    use CreatesAdsHttpFixtures;
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

    // ---------------------------------------------------------------
    // States (§16)
    // ---------------------------------------------------------------

    public function test_not_connected_shows_the_connect_state_with_a_button_for_a_manager(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Connect Google Ads to see where your ad budget is generating results.', $html);
        $this->assertStringContainsString('data-role="connect-google-ads"', $html);
        $this->assertStringContainsString('See where your budget is turning into leads — and what is wasting money.', $html);
        $this->assertStringContainsString('name="_token"', $html, 'the connect control is a CSRF-protected POST');
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_not_connected_without_manage_permission_explains_to_ask_the_owner(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer, [self::VIEW]);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="connect-google-ads"', $html);
        $this->assertStringContainsString('Ask the owner of this account to connect Google Ads.', $html);
    }

    public function test_a_revoked_connection_offers_reconnect_not_the_dead_data_state(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        BusinessGoogleConnection::query()->where('business_id', $business->id)->update(['state' => GoogleConnectionState::Revoked->value, 'refresh_token_encrypted' => null]);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Google Ads needs to be reconnected', $html);
        $this->assertStringContainsString('Reconnect Google Ads', $html);
    }

    public function test_connected_without_an_account_links_to_account_selection(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Choose your Google Ads account', $html);
        $this->assertStringContainsString('href="' . $this->adsUrl($workspace, $business, 'accounts') . '"', $html);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_connected_with_no_campaigns_explains_instead_of_showing_zeros(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="no-campaigns"', $html);
        $this->assertStringContainsString('No campaigns to show yet', $html);
        $this->assertStringNotContainsString('data-role="kpi-cards"', $html);
    }

    public function test_never_synced_says_the_first_update_is_pending(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['last_successful_sync_at' => null, 'data_through_date' => null]);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Waiting for the first update from Google Ads.', $html);
        $this->assertStringContainsString('data-role="no-period-data"', $html);
        $this->assertStringContainsString('still loading your first update', $html);
    }

    // ---------------------------------------------------------------
    // Figures (§9)
    // ---------------------------------------------------------------

    public function test_data_present_shows_the_kpis_in_the_account_currency(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['monthly_budget_target_micros' => 250_000_000]);
        $this->seedOctoberData($account); // 4 days x $26 = $104 spend, 8 conversions
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="kpi-cards"', $html);
        $this->assertStringContainsString('USD 104.00', $this->roleText($html, 'kpi-spend-this-month-value'));
        $this->assertSame('8', $this->roleText($html, 'kpi-conversions-value'));
        $this->assertSame('USD 13.00', $this->roleText($html, 'kpi-cpl-value'));
        $this->assertStringContainsString('10.0%', $this->roleText($html, 'kpi-conversion-rate-value'));
        $this->assertSame('—', $this->roleText($html, 'kpi-conversion-value-value'), 'no conversion value => a dash, hidden figure');
        $this->assertStringContainsString('Google conversions', $html);
        $this->assertStringContainsString('Cost per conversion', $html);
        $this->assertStringNotContainsString('>Leads<', $html, 'Google conversions are never relabelled as Leads');
        $this->assertStringContainsString('data-role="ads-currency"', $html);
        $this->assertStringContainsString('USD', $html);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_missing_conversion_data_shows_a_dash_never_zero_for_cost_per_conversion(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-04', 26_000_000, 20, null);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame('—', $this->roleText($html, 'kpi-cpl-value'));
        $this->assertSame('—', $this->roleText($html, 'kpi-conversions-value'));
        $this->assertSame('—', $this->roleText($html, 'kpi-conversion-rate-value'));
    }

    public function test_zero_conversions_shows_cost_per_conversion_as_a_dash(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-04', 26_000_000, 20, '0');
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame('—', $this->roleText($html, 'kpi-cpl-value'));
    }

    public function test_projected_month_end_carries_a_low_confidence_note_and_conversion_value_appears_when_real(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-04', 26_000_000, 20, '2', '40');
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame('USD 806.00', $this->roleText($html, 'kpi-projected-value'), '$104 over 4 days, projected over 31');
        $this->assertStringContainsString('Early estimate', $this->roleText($html, 'kpi-projected-note'));
        $this->assertSame('USD 160.00', $this->roleText($html, 'kpi-conversion-value-value'));
    }

    public function test_freshness_line_shows_updated_and_data_through(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['data_through_date' => '2026-10-03']);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Updated 1 hour ago', $html);
        $this->assertStringContainsString('Data through Oct 3, 2026', $html);
        $this->assertStringNotContainsString('data-role="freshness-warning"', $html);
    }

    public function test_a_failed_latest_sync_keeps_the_data_and_shows_a_calm_warning(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['last_sync_failure_code' => 'provider_unavailable']);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="freshness-warning"', $html);
        $this->assertStringContainsString('Showing your last successful update.', $html);
        $this->assertStringContainsString('Latest refresh failed: Google Ads is temporarily unavailable.', $html);
        $this->assertStringContainsString('USD 104.00', $html, 'the last good figures are still shown');
        $this->assertStringNotContainsString('alert-danger', $this->between($html, 'data-role="ads-freshness"', 'data-role="period-selector"'), 'never a scary red alert');
    }

    // ---------------------------------------------------------------
    // Periods never call Google
    // ---------------------------------------------------------------

    public function test_every_period_is_served_from_cache_with_zero_provider_calls(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->seedCampaignDays($account, '111', '2026-09-20', '2026-09-30', 10_000_000, 10, '1');
        $this->asAdsUser($customer);

        foreach (['last_7', 'last_30', 'this_month', 'previous_month', 'not-a-period'] as $period) {
            $this->get($this->adsUrl($workspace, $business, 'index', ['period' => $period]))->assertOk();
            $this->getJson($this->adsUrl($workspace, $business, 'series', ['period' => $period]))->assertOk();
        }

        $this->assertSame(0, $this->fakeAds->callCount(), 'changing the period must never call Google');
    }

    public function test_the_period_changes_the_figures_and_marks_the_selected_period(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->seedCampaignDays($account, '111', '2026-09-20', '2026-09-30', 10_000_000, 10, '1');
        $this->asAdsUser($customer);

        $previous = $this->get($this->adsUrl($workspace, $business, 'index', ['period' => 'previous_month']))->assertOk()->getContent();

        $this->assertSame('11', $this->roleText($previous, 'kpi-conversions-value'), 'September: 11 days x 1 conversion');
        $this->assertSame('USD 10.00', $this->roleText($previous, 'kpi-cpl-value'));
        $this->assertMatchesRegularExpression('/data-period="previous_month"/', $previous);
        $this->assertMatchesRegularExpression('/aria-current="true"\s+data-period="previous_month"/', $previous);
        $this->assertStringContainsString('USD 104.00', $this->roleText($previous, 'kpi-spend-this-month-value'), '"Spend this month" ignores the chosen period');

        $default = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-current="true"\s+data-period="last_30"/', $default);
    }

    // ---------------------------------------------------------------
    // Series JSON
    // ---------------------------------------------------------------

    public function test_the_series_endpoint_returns_the_chart_shape_for_the_period(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer);

        $json = $this->getJson($this->adsUrl($workspace, $business, 'series', ['period' => 'this_month']))
            ->assertOk()
            ->assertJsonStructure(['period' => ['key', 'from', 'to', 'timezone', 'days'], 'currency', 'granularity', 'has_data', 'has_conversion_data', 'labels', 'tooltips', 'series' => ['spend', 'spend_micros', 'clicks', 'conversions', 'cpl']])
            ->json();

        $this->assertSame('this_month', $json['period']['key']);
        $this->assertSame('USD', $json['currency']);
        $this->assertTrue($json['has_data']);
        $this->assertCount(4, $json['labels']);
        $this->assertEquals([26.0, 26.0, 26.0, 26.0], $json['series']['spend']);
        $this->assertEquals([13.0, 13.0, 13.0, 13.0], $json['series']['cpl']);
    }

    public function test_the_series_endpoint_is_scoped_to_the_callers_own_business(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);

        [$otherCustomer] = $this->adsHttpTenant();
        $this->asAdsUser($otherCustomer);

        $this->getJson($this->adsUrl($workspace, $business, 'series'))->assertNotFound();
    }

    public function test_the_series_endpoint_is_404_without_an_account_and_without_the_view_permission(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->getJson($this->adsUrl($workspace, $business, 'series'))->assertNotFound();

        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer, [self::MANAGE]);

        $this->getJson($this->adsUrl($workspace, $business, 'series'))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Core vs Growth
    // ---------------------------------------------------------------

    public function test_a_core_business_sees_the_overview_with_a_calm_note_and_no_links_it_cannot_open(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant(WorkspacePlanTier::Core);
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="core-note"', $html);
        $this->assertStringContainsString('part of the Growth plan', $html);
        $this->assertStringNotContainsString($this->adsUrl($workspace, $business, 'budget'), $html);
        $this->assertStringContainsString($this->adsUrl($workspace, $business, 'settings'), $html);
        $this->assertStringNotContainsString('data-role="waste-teaser"', $html);
    }

    public function test_a_growth_business_has_no_upgrade_note_and_links_to_budget(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->seedOctoberData($account);
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="core-note"', $html);
        $this->assertStringContainsString($this->adsUrl($workspace, $business, 'budget'), $html);
    }

    // ---------------------------------------------------------------
    // Escaping and secrets
    // ---------------------------------------------------------------

    public function test_provider_text_is_escaped_and_no_secret_reaches_the_html(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['descriptive_name' => '<script>alert("acct")</script>']);
        $this->seedCampaign($account, '111', '<img src=x onerror=alert(1)>');
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-04', 26_000_000, 20, '2');
        $this->asAdsUser($customer);

        foreach (['index', 'settings', 'budget'] as $page) {
            $html = $this->get($this->adsUrl($workspace, $business, $page))->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert("acct")</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
            $this->assertStringNotContainsString('plain-ads-refresh-token', $html);
            $this->assertStringNotContainsString('test-ads-client-secret', $html);
            $this->assertStringNotContainsString('refresh_token', $html);
            $this->assertStringNotContainsString('developer-token', $html);
        }

        $budget = $this->get($this->adsUrl($workspace, $business, 'budget'))->getContent();
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $budget);

        $settings = $this->get($this->adsUrl($workspace, $business, 'settings'))->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;acct&quot;)&lt;/script&gt;', $settings);
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function roleText(string $html, string $role): string
    {
        $this->assertSame(1, preg_match('/data-role="' . preg_quote($role, '/') . '"[^>]*>(.*?)<\/(?:p|dd|td|span)>/s', $html, $m), "[{$role}] not found");

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $end = strpos($html, $to, $start === false ? 0 : $start);

        return $start === false ? '' : substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
