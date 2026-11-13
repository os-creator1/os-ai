<?php

namespace Tests\Feature\Marketing;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SupportAndZoomDocumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://getmotiongrove.test',
            'marketing.enabled' => true,
            'marketing.hosts' => [],
            'marketing.show_placeholders' => false,
        ]);
    }

    public function test_public_support_page_has_contact_hours_sla_and_a_real_email_case_link(): void
    {
        $response = $this->get('https://getmotiongrove.test/support')->assertOk();
        $response->assertSee('support@getmotiongrove.com');
        $response->assertSee('legal@getmotiongrove.com');
        $response->assertSee('Monday–Friday');
        $response->assertSee('Within 2 business days');
        $response->assertSee('mailto:support@getmotiongrove.com?subject=MotionGrove%20support%20case', false);
        $response->assertSee('/docs/zoom');
        $response->assertSee('<h1>', false);
        $response->assertSee('Support Center | MotionGrove');
    }

    public function test_zoom_guide_explains_add_use_remove_and_data_consequences(): void
    {
        $response = $this->get('https://getmotiongrove.test/docs/zoom')->assertOk();
        foreach (['Connect your Zoom account', 'Use Zoom meetings in bookings', 'Disconnect or remove MotionGrove from Zoom', 'What happens to existing meetings and data?', 'Troubleshooting', 'Zoom App Marketplace'] as $text) {
            $response->assertSee($text);
        }
        $response->assertSee('https://marketplace.zoom.us/');
        $response->assertSee('support@getmotiongrove.com');
        $response->assertSee('<link rel="canonical" href="https://getmotiongrove.test/docs/zoom">', false);
    }

    public function test_both_pages_are_marketing_host_only(): void
    {
        config(['marketing.hosts' => ['getmotiongrove.test']]);
        $this->get('https://getmotiongrove.test/support')->assertOk();
        $this->get('https://getmotiongrove.test/docs/zoom')->assertOk();

        $this->get('https://app.getmotiongrove.test/support')->assertNotFound();
        $this->get('https://agency-customer.test/docs/zoom')->assertNotFound();
    }

    public function test_footer_links_to_support_and_zoom_and_keeps_legal_hub(): void
    {
        $html = $this->get('https://getmotiongrove.test/support')->assertOk()->getContent();
        $this->assertStringContainsString('Support center', $html);
        $this->assertStringContainsString('Zoom guide', $html);
        $this->assertStringContainsString('All legal documents', $html);
        $this->assertStringContainsString('mailto:support@getmotiongrove.com', $html);
        $this->assertSame(1, substr_count($html, 'href="https://getmotiongrove.test/terms-of-service"') >= 1 ? 1 : 0);
    }

    public function test_routes_are_named_and_publicly_registered(): void
    {
        $this->assertTrue(Route::has('marketing.support'));
        $this->assertTrue(Route::has('marketing.zoom-docs'));
        $this->assertSame('https://getmotiongrove.test/support', route('marketing.support'));
        $this->assertSame('https://getmotiongrove.test/docs/zoom', route('marketing.zoom-docs'));
    }
}
