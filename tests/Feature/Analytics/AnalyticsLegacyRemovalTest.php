<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §12.3 (29 legacy customer reporting-path declarations
 * removed), §12.3.1 (the three unscoped campaign controls are superseded
 * by B1's canonical Business-scoped routes), §12.4 / §14 (ghost AI
 * Analytics / Hot Leads surfaces removed), §15.3 (no export), §21
 * "Removed legacy Reports routes", "Removed exports", "Removed legacy
 * campaign controls", "Canonical Business-scoped campaign controls still
 * work", "Removed ghost routes".
 *
 * Every removed path is asserted for an authenticated customer holding
 * `view_reports` (and, for the campaign controls, the campaign's OWN
 * owner) so the tests prove the routes are gone, not merely scoped.
 */
class AnalyticsLegacyRemovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    /** @return array<int, array{0: string, 1: string}> */
    private function legacyReportRoutes(string $campaignUid, string $reportUid): array
    {
        return [
            ['post', '/reports/' . $reportUid . '/destroy'],
            ['get', '/reports/all'],
            ['post', '/reports/' . $reportUid . '/view'],
            ['post', '/reports/export'],
            ['get', '/reports/export/sent'],
            ['get', '/reports/export/receive'],
            ['get', '/reports/export/' . $campaignUid],
            ['get', '/reports/received'],
            ['get', '/reports/sent'],
            ['get', '/reports/campaigns'],
            ['post', '/reports/search'],
            ['post', '/reports/search/received'],
            ['post', '/reports/search/sent'],
            ['post', '/reports/search/campaigns'],
            ['post', '/reports/batch_action'],
            ['get', '/reports/campaigns/' . $campaignUid . '/edit'],
            ['post', '/reports/campaigns/' . $campaignUid . '/edit'],
            ['get', '/reports/campaigns/' . $campaignUid . '/overview'],
            ['post', '/reports/campaigns/' . $campaignUid . '/reports'],
            ['post', '/reports/campaigns/' . $campaignUid . '/delete'],
            ['post', '/reports/campaign/batch_action'],
            ['get', '/reports/campaign/export'],
            ['get', '/reports/analyze'],
            ['post', '/reports/analyze'],
            ['post', '/reports/' . $reportUid . '/dlr'],
            ['post', '/reports/campaigns/' . $campaignUid . '/pause'],
            ['post', '/reports/campaigns/' . $campaignUid . '/restart'],
            ['post', '/reports/campaigns/' . $campaignUid . '/resend'],
            ['get', '/view-charts'],
        ];
    }

    public function test_all_29_legacy_customer_reporting_routes_are_gone(): void
    {
        [$customer, $business] = $this->tenant();
        $campaign = $this->campaign($business);
        $reportId = $this->report($business, $business->customer_id);
        $reportUid = (string) \DB::table('reports')->where('id', $reportId)->value('uid');
        $this->authenticateAsCustomer($customer, ['view_reports', 'sms_campaign_builder']);

        $routes = $this->legacyReportRoutes($campaign->uid, $reportUid);
        $this->assertCount(29, $routes);

        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertNotFound();
        }

        foreach (['customer.reports.all', 'customer.reports.campaigns', 'customer.reports.analyze', 'customer.reports.export.all', 'customer.reports.campaign.pause', 'customer.reports.campaign.restart', 'customer.reports.campaign.resend', 'customer.view.charts'] as $name) {
            $this->assertFalse(Route::has($name), $name . ' must no longer exist.');
        }

        $this->assertDatabaseHas('reports', ['id' => $reportId]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    public function test_legacy_campaign_controls_are_gone_even_for_the_campaigns_own_owner(): void
    {
        [$customer, $business] = $this->tenant();
        $campaign = $this->campaign($business, ['status' => Campaigns::STATUS_PAUSED]);
        $this->authenticateAsCustomer($customer, ['sms_campaign_builder']);

        foreach (['pause', 'restart', 'resend'] as $action) {
            $this->post('/reports/campaigns/' . $campaign->uid . '/' . $action)->assertNotFound();
        }

        $this->assertSame(Campaigns::STATUS_PAUSED, $campaign->fresh()->status);
    }

    public function test_canonical_b1_business_scoped_campaign_controls_remain_operational(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $campaign = $this->campaign($business, ['status' => Campaigns::STATUS_PROCESSING]);
        $this->authenticateAsCustomer($customer, ['sms_campaign_builder']);

        foreach (['pause', 'restart', 'resend'] as $action) {
            $route = Route::getRoutes()->getByName('customer.workspaces.businesses.outreach.campaigns.' . $action);
            $this->assertNotNull($route, 'B1 canonical ' . $action . ' route must exist.');
            $this->assertContains('POST', $route->methods());
            $this->assertStringContainsString('OutreachController@' . $action, $route->getActionName());
        }

        // pause is the one lifecycle action without a send side effect.
        $this->post(route('customer.workspaces.businesses.outreach.campaigns.pause', [$workspace->uid, $business->uid, $campaign->uid]))
            ->assertRedirect();

        $this->assertSame(Campaigns::STATUS_PAUSED, $campaign->fresh()->status);
    }

    public function test_ghost_ai_analytics_and_hot_leads_surfaces_are_gone(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAsCustomer($customer, ['chat_box']);

        $this->get('/admin/ai-analytics')->assertNotFound();
        $this->get('/admin/hot-leads')->assertNotFound();
        $this->post('/admin/hot-leads/mark-called', ['id' => 1])->assertNotFound();
        $this->post('/admin/ai-variants/update')->assertNotFound();
        $this->post('/admin/ai-analytics/book/1')->assertNotFound();

        $this->assertFalse(Route::has('admin.ai_variants.update'));
        $this->assertFalse(Route::has('admin.ai.booked'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Admin/AiAnalyticsController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Admin/HotLeadController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/Admin/MarkHotLeadCalledRequest.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/ai_analytics.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/hot_leads.blade.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Customer/ReportsController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/Reports/DashboardRequest.php'));
        $this->assertDirectoryDoesNotExist(resource_path('views/customer/Reports'));
    }

    public function test_admin_operational_reports_are_untouched(): void
    {
        $this->assertTrue(Route::has('admin.reports.campaigns'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/ReportsController.php'));
        $this->assertFileExists(app_path('Models/Reports.php'));
        $this->assertFileExists(app_path('Models/TrackingLog.php'));
    }
}
