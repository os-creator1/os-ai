<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §3 (forbidden sources), §10 (non-metrics), §16 (Agency
 * Prospecting separation), §17 (Billing separation), §21 "No ChatBox",
 * "No fake revenue/ROI", "Agency Prospecting separation", "Billing
 * separation".
 */
class AnalyticsSeparationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private const FORBIDDEN_TABLES = ['chat_boxes', 'chat_box_messages', 'agency_prospect', 'business_usage', 'invoices', 'subscriptions', 'subscription_transactions', 'payment_'];

    public function test_no_analytics_read_queries_a_forbidden_source(): void
    {
        // Measured at the presenter/query layer, which is exactly the code
        // B5 owns: the shared page chrome (plan card, notifications) is
        // outside Analytics and is not what this contract restricts.
        [, $business] = $this->tenant();
        config(['opportunity.enabled' => true]);
        $presenter = app(BusinessAnalyticsPresenter::class);
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);

        $sql = $this->capturedSql(function () use ($presenter, $business, $range): void {
            $presenter->buildOverview($business, $range);
            $presenter->buildCampaignsPage($business, $range, 1);
            $presenter->buildSeries($business, $range);
        });

        $analyticsSql = array_filter($sql, fn (string $s) => preg_match('/\b(reports|tracking_logs|campaigns|contacts|contact_groups|opportunities|opportunity_runs|automation_executions)\b/', $s) === 1);
        $this->assertNotEmpty($analyticsSql);
        $this->assertCount(count($sql), $analyticsSql, 'Every analytics query reads an authorized source: ' . implode(' | ', $sql));

        foreach ($sql as $statement) {
            foreach (self::FORBIDDEN_TABLES as $table) {
                $this->assertStringNotContainsString($table, $statement, 'Analytics must never query ' . $table);
            }
        }
    }

    public function test_analytics_sources_never_reference_forbidden_models_or_tables(): void
    {
        $files = array_merge(
            glob(app_path('Library/Analytics/*.php')),
            glob(app_path('DTO/Analytics/*.php')),
            [app_path('Http/Controllers/Customer/Business/AnalyticsController.php')],
            glob(resource_path('views/customer/business/analytics/*.blade.php')),
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            // Comments stripped for PHP: the assertion is about code paths,
            // not about docblocks that name what is forbidden.
            $source = str_ends_with($file, '.php') && ! str_ends_with($file, '.blade.php') ? php_strip_whitespace($file) : file_get_contents($file);

            foreach (['chat_box', 'ChatBox', 'ChatBoxMessage', 'agency_prospect', 'AgencyProspect', 'business_usage', 'UsageWallet', 'invoices', 'subscriptions', 'payment_'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file) . ' must not reference ' . $forbidden);
            }
        }
    }

    public function test_overview_renders_no_fake_revenue_roi_or_pipeline_language_and_no_prospecting_link(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        config(['opportunity.enabled' => true]);
        $this->opportunity($business);
        $this->authenticateAsCustomer($customer);

        $content = $this->overview($workspace, $business)->assertOk()->getContent();
        $body = substr($content, strpos($content, 'id="business-analytics"'));

        foreach (['revenue', 'ROI', 'profit', 'earnings', 'deal', 'pipeline', 'won', 'lost', 'close rate', 'booking', 'conversion rate', 'lead source', 'cost', 'spend', 'delivery rate', 'handset delivery rate', 'reply rate', 'response time', 'engagement score'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, 'The overview must not present "' . $forbidden . '".');
        }

        $this->assertStringNotContainsString(route('customer.prospecting.index'), $body, 'Agency Prospecting is a different scope and is never linked.');
        $this->assertStringContainsString(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]), $body);
        // Slice 2B: Conversations are linked for THIS Business directly, not
        // through the generic /chat-box chooser (still a link, never data).
        $this->assertStringContainsString(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]), $body);
        $this->assertStringContainsString('AI Business Advisor recommendations', $body);
    }
}
