<?php

namespace Tests\Feature\AgencyOutreach;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * Every Outreach page renders its own header (title, subtitle, tabs), so the shared shell
 * heading (panels.breadcrumb) must not print a second page title. The browser <title> stays.
 */
class OutreachSingleHeadingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    public function test_outreach_pages_do_not_print_the_shared_shell_heading_twice(): void
    {
        $a = $this->outreachAgency('Heading');
        $this->authenticateAs($a['customer']);

        $pages = [
            $this->outreachRoute('overview', $a['workspace']),
            $this->outreachRoute('prospects.index', $a['workspace']),
            $this->outreachRoute('conversations.index', $a['workspace']),
            $this->outreachRoute('campaigns.index', $a['workspace']),
            $this->outreachRoute('script.show', $a['workspace']),
            $this->outreachRoute('phone-messaging', $a['workspace']),
            $this->outreachRoute('settings.show', $a['workspace']),
            $this->outreachRoute('campaigns.show', $a['workspace'], [$a['campaign']->uid]),
            $this->outreachRoute('prospects.show', $a['workspace'], [$a['prospect']->getRouteKey()]),
        ];

        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('content-header-title', $html, "Shared shell heading printed twice on {$url}");
        }
    }

    public function test_campaign_detail_after_launch_has_a_single_page_heading_and_keeps_the_browser_title(): void
    {
        $a = $this->outreachAgency('Launched');
        $this->authenticateAs($a['customer']);
        $a['campaign']->update(['status' => 'active']);

        $html = $this->get($this->outreachRoute('campaigns.show', $a['workspace'], [$a['campaign']->uid]))->assertOk()->getContent();

        $this->assertStringNotContainsString('content-header-title', $html);
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString($a['campaign']->name, $html);
    }
}
