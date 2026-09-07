<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §13 (M2 structure and components), §13.3 (ApexCharts +
 * PlatformTheme only), §21 "M2 component adoption".
 */
class AnalyticsViewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    public function test_overview_uses_m2_primitives_and_the_shared_chart_namespace(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->report($business, $business->customer_id);
        $this->campaign($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();

        foreach (['ds-card', 'ds-table', 'rounded-pill', 'data-role="stat-cards"', 'data-role="outcome-breakdown"', 'data-role="chart-contact-growth"', 'data-role="chart-message-volume"', 'data-role="automations-panel"', 'window.PlatformTheme', 'apexcharts.min.js'] as $marker) {
            $response->assertSee($marker, false);
        }

        $response->assertSee('Provider-accepted rate');
        $response->assertSee('Unresolved / in flight');
        $response->assertSee('not handset delivery');
        $response->assertSee('Skipped means the send was skipped');
        $response->assertDontSee('7367F0', false);
    }

    public function test_business_with_no_data_renders_the_empty_states(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()->assertSee('ds-empty-state', false)->assertSee('No campaigns yet');
        $this->campaignsPage($workspace, $business)->assertOk()->assertSee('No campaigns in this range');
    }

    public function test_analytics_views_reference_platform_theme_and_no_legacy_purple(): void
    {
        $source = file_get_contents(resource_path('views/customer/business/analytics/overview.blade.php'));

        $this->assertStringContainsString('PlatformTheme', $source);
        $this->assertStringContainsString('chartPalette', $source);
        $this->assertStringNotContainsStringIgnoringCase('7367F0', $source);

        foreach (['chart.js', 'echarts', 'highcharts', 'vue', 'react'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden . '.', $source);
        }
    }

    public function test_customer_nav_offers_analytics_and_no_legacy_reports_or_ghost_entries(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $content = $this->overview($workspace, $business)->assertOk()->getContent();

        $this->assertStringContainsString(url('analytics'), $content);
        foreach ([url('reports/analyze'), url('reports/all'), url('reports/campaigns'), url('admin/hot-leads'), url('admin/ai-analytics')] as $legacy) {
            $this->assertStringNotContainsString($legacy, $content);
        }
    }
}
