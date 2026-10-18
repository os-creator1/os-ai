<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaAdsLevel;
use App\Enums\MetaAds\MetaConnectionState;
use App\Library\MetaAds\MetaAdsMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §5/§9) — the Meta Overview: every state
 * (not connected, expired, no account, ready with data, no data, no result
 * type), Core vs Growth, freshness, cached-data-only period switching, the
 * chart series, escaping and the "never a provider id" rule.
 */
class MetaAdsOverviewPageTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    private function usd(int $micros): string
    {
        return MetaAdsMoney::format($micros, 'USD');
    }

    // ---------------------------------------------------------------
    // States
    // ---------------------------------------------------------------

    public function test_not_connected_offers_connect_to_a_manager_and_names_the_owner_to_a_viewer(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="not_connected"', $html);
        $this->assertStringContainsString('Connect Meta Ads', $html);
        $this->assertStringContainsString('data-role="connect-meta-ads"', $html);
        $this->assertStringContainsString($this->metaPage($workspace, $business, 'connect'), $html);

        $this->asMetaUser($customer, [self::META_VIEW]);
        $viewer = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="connect-meta-ads"', $viewer);
        $this->assertStringContainsString('Ask the owner of this account to connect Meta.', $viewer);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_an_expired_or_revoked_connection_shows_a_reconnect_call_to_action(): void
    {
        foreach ([MetaConnectionState::Expired, MetaConnectionState::Revoked] as $state) {
            [$customer, $business, $workspace] = $this->metaHttpTenant(name: 'Co ' . $state->value);
            $this->activeMetaConnection($business, ['state' => $state, 'access_token_encrypted' => null, 'token_expires_at' => now()->subDay()]);
            $this->asMetaUser($customer);

            $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

            $this->assertStringContainsString('data-state="expired"', $html, $state->value);
            $this->assertStringContainsString('Meta needs to be reconnected', $html);
            $this->assertStringContainsString('Reconnect Meta', $html);
            $this->assertStringContainsString('data-role="reconnect-meta-ads"', $html);
        }
    }

    public function test_connected_without_an_account_links_to_the_account_chooser(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="no_account"', $html);
        $this->assertStringContainsString('Choose your Meta ad account', $html);
        $this->assertStringContainsString($this->metaPage($workspace, $business, 'accounts'), $html);
    }

    public function test_ready_with_data_shows_the_kpis_in_the_account_currency_with_the_result_label(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['monthly_budget_target_micros' => 300_000_000, 'target_cost_per_result_micros' => 20_000_000]);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="kpi-cards"', $html);
        $this->assertStringContainsString($this->usd(78_000_000), $html, 'spend: 3 days x $26');
        $this->assertStringContainsString('Leads (on-Facebook forms)', $html, 'the result type label is shown');
        $this->assertStringContainsString('>6<', preg_replace('/\s+/', '', $html) ?? '', 'results: 3 days x 2');
        $this->assertStringContainsString($this->usd(13_000_000), $html, 'cost per result: $78 / 6');
        $this->assertStringContainsString('Target ' . $this->usd(20_000_000), $html);
        $this->assertStringContainsString('Photo Booth Co - Ads', $html);
        $this->assertStringContainsString('data-role="account-currency"', $html);
        $this->assertStringContainsString('Projected month-end spend', $html);
        $this->assertStringContainsString('(target ' . $this->usd(300_000_000) . ')', $html);
        $this->assertStringContainsString('data-role="chart-trend"', $html);
        $this->assertStringContainsString('data-role="trend-table"', $html, 'the table fallback is server-rendered');
        $this->assertStringNotContainsString('Choose a result type', $html);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_without_a_chosen_result_type_results_are_unavailable_and_a_call_to_action_points_to_settings(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['result_action_type' => null]);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Choose a result type', $html);
        $this->assertStringContainsString('data-role="result-type-prompt"', $html);
        $this->assertStringContainsString($this->metaPage($workspace, $business, 'settings'), $html);
        $this->assertStringContainsString($this->usd(78_000_000), $html, 'spend is still shown');
        $this->assertMatchesRegularExpression('/data-role="kpi-results-value">\s*&mdash;\s*</', $html, 'results are a dash, never 0');
        $this->assertMatchesRegularExpression('/data-role="kpi-cpr-value">\s*—\s*</u', $html, 'cost per result is a dash');
    }

    public function test_a_campaign_without_rows_in_the_period_says_so_calmly(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->seedMetaCampaign($account, 'Quiet Campaign');
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="no-period-data"', $html);
        $this->assertStringContainsString('No ad activity was recorded for this period.', $html);
        $this->assertStringNotContainsString('$0.00', $html, 'no data is a dash, not zero money');
    }

    public function test_a_never_synced_account_without_rows_says_the_first_update_is_loading(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['last_successful_sync_at' => null, 'data_through_date' => null]);
        $this->seedMetaCampaign($account, 'New Campaign');
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('We are still loading your first update from Meta.', $html);
        $this->assertStringContainsString('Waiting for the first update from Meta.', $html);
    }

    public function test_an_account_without_campaigns_shows_the_no_campaigns_state(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('No campaigns to show yet', $html);
        $this->assertStringNotContainsString('data-role="kpi-cards"', $html);
    }

    // ---------------------------------------------------------------
    // Core vs Growth, freshness, currency
    // ---------------------------------------------------------------

    public function test_core_and_growth_see_the_same_figures_but_only_growth_gets_links_into_the_module(): void
    {
        $html = [];

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->metaHttpTenant($tier, 'Tier ' . $tier->value);
            $account = $this->metaSelected($business);
            $campaign = $this->seedMetaCampaign($account, 'Spendy Campaign');
            $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 20_000_000, 0);
            $this->asMetaUser($customer);

            $html[$tier->value] = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        }

        foreach ($html as $page) {
            $this->assertStringContainsString('Spendy Campaign has spend but no results yet', $page, 'the attention fact is shown to both');
            $this->assertStringContainsString($this->usd(60_000_000), $page);
        }

        $this->assertStringContainsString('data-role="core-note"', $html[WorkspacePlanTier::Core->value]);
        $this->assertStringNotContainsString('/ads/meta/campaigns/', $html[WorkspacePlanTier::Core->value], 'Core has no campaign page to link to');
        $this->assertStringNotContainsString('data-role="core-note"', $html[WorkspacePlanTier::Growth->value]);
        $this->assertStringContainsString('/ads/meta/campaigns/', $html[WorkspacePlanTier::Growth->value]);
    }

    public function test_freshness_line_and_a_calm_warning_for_a_failed_refresh(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['last_successful_sync_at' => now()->subHour()]);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Updated 1 hour ago', $html);
        $this->assertStringContainsString('Data through Oct 3, 2026', $html);
        $this->assertStringNotContainsString('data-role="freshness-warning"', $html);

        $account->forceFill(['last_sync_failure_code' => 'rate_limited'])->save();
        $failed = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="freshness-warning"', $failed);
        $this->assertStringContainsString('Showing your last successful update.', $failed);
        $this->assertStringContainsString('Meta is rate limiting requests.', $failed);
        $this->assertStringContainsString($this->usd(78_000_000), $failed, 'the last good figures stay');
    }

    public function test_a_foreign_account_currency_is_stated_when_it_differs_from_the_business_currency(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        DB::table('businesses')->where('id', $business->id)->update(['currency_code' => 'USD']);
        $account = $this->metaSelected($business->fresh(), ['currency_code' => 'EUR']);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="currency-note"', $html);
        $this->assertStringContainsString('ad account currency (EUR)', $html);
        $this->assertStringContainsString('business currency (USD)', $html);
        $this->assertStringContainsString(MetaAdsMoney::format(78_000_000, 'EUR'), $html);
    }

    // ---------------------------------------------------------------
    // Periods, series
    // ---------------------------------------------------------------

    public function test_changing_the_period_re_filters_cached_rows_and_never_calls_meta(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        foreach (['last_7', 'last_30', 'this_month', 'previous_month'] as $period) {
            $html = $this->get($this->metaPage($workspace, $business, 'index', ['period' => $period]))->assertOk()->getContent();
            $this->assertStringContainsString('data-period="' . $period . '"', $html);
            $this->assertMatchesRegularExpression('/data-period="' . $period . '"[^>]*>/', $html);
        }

        $previous = $this->get($this->metaPage($workspace, $business, 'index', ['period' => 'previous_month']))->getContent();
        $this->assertStringContainsString('No ad activity was recorded for this period.', $previous);

        $this->get($this->metaPage($workspace, $business, 'index', ['period' => 'nonsense; DROP']))->assertOk();

        $this->assertSame(0, $this->fakeMeta->callCount(), 'a period is a cached-row filter, never a provider call');
    }

    public function test_the_series_endpoint_returns_cached_daily_figures_and_404s_without_an_account(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $json = $this->getJson($this->metaPage($workspace, $business, 'series', ['period' => 'this_month']))->assertOk()->json();

        $this->assertSame('USD', $json['currency']);
        $this->assertTrue($json['has_data']);
        $this->assertCount(count($json['labels']), $json['series']['spend']);
        $this->assertEquals([26, 26, 26], array_slice($json['series']['spend'], 0, 3));
        $this->assertStringNotContainsString($account->ad_account_id, json_encode($json));

        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->metaHttpTenant(name: 'No Account Co');
        $this->asMetaUser($otherCustomer);
        $this->getJson($this->metaPage($otherWorkspace, $otherBusiness, 'series'))->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    // ---------------------------------------------------------------
    // Escaping and provider ids
    // ---------------------------------------------------------------

    public function test_provider_and_customer_strings_are_escaped_and_no_provider_id_is_rendered(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['name' => '<img src=x onerror=alert(1)>Evil Account']);
        $campaign = $this->seedMetaCampaign($account, 'Evil <script>alert(1)</script> Campaign');
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 20_000_000, 0);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Evil Account', $html);

        foreach ([$account->ad_account_id, (string) $campaign->external_campaign_id] as $providerId) {
            $this->assertStringNotContainsString($providerId, $html, 'a Meta id is never shown');
        }
    }

    public function test_the_overview_makes_no_provider_call_and_never_leaks_a_token(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->seedMetaOctober($account);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('plain-meta-access-token', $html);
        $this->assertStringNotContainsString('test-meta-app-secret', $html);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }
}
