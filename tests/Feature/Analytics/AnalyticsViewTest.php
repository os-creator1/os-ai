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

        // The automations panel is proven in AnalyticsAdvisorAutomationTest,
        // where executions exist; Results shows it only when automations ran.
        //
        // Owner product decision ("remove Messaging channel from normal UX",
        // Results cleanup): the sent/failed/processing breakdown and its
        // chart moved to Settings -> Text messaging -> Delivery & usage —
        // TextMessagingDeliveryUsageTest owns proving those markers there
        // now. Results keeps only "Messages received" as a genuine outcome.
        foreach (['ds-card', 'ds-table', 'rounded-pill', 'data-role="stat-cards"', 'data-role="chart-contact-growth"', 'data-role="campaigns-panel"', 'window.PlatformTheme', 'apexcharts.min.js'] as $marker) {
            $response->assertSee($marker, false);
        }

        $response->assertDontSee('data-role="results-messages"', false);
        $response->assertDontSee('data-role="outcome-breakdown"', false);
        $response->assertDontSee('data-role="chart-message-volume"', false);
        $response->assertDontSee('Provider-accepted rate');
        $response->assertDontSee('handset');
        $response->assertDontSee('7367F0', false);
    }

    public function test_business_with_no_data_renders_the_empty_states(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        // One calm empty state for a period with no activity — never a wall
        // of empty technical cards, and no campaigns card at all.
        $this->overview($workspace, $business)->assertOk()
            ->assertSee('ds-empty-state', false)
            ->assertSee('Nothing to show for this period yet')
            ->assertDontSee('data-role="campaigns-panel"', false)
            ->assertDontSee('data-role="results-messages"', false);
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

        // Customer Experience Slice 1B: the customer shell links Analytics
        // directly to the canonical Business-scoped overview of the selected
        // Business (the bare /analytics chooser remains registered as the
        // legacy entry, but the menu no longer points at it).
        $this->assertStringContainsString(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]), $content);
        foreach ([url('reports/analyze'), url('reports/all'), url('reports/campaigns'), url('admin/hot-leads'), url('admin/ai-analytics')] as $legacy) {
            $this->assertStringNotContainsString($legacy, $content);
        }
    }
}
