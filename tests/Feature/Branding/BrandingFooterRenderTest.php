<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingPresenter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §6.4/§9 item 5. The
 * rendered footer contains the configured company name and copyright
 * wording plus the current year, and contains neither "Ultimate SMS" nor
 * "Codeglen" in the default (unconfigured) state.
 */
class BrandingFooterRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(BrandingPresenter::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
    }

    public function test_footer_component_renders_clean_in_the_default_state(): void
    {
        config([
            'app.name' => 'AI Business OS',
            'app.footer_company_name' => null,
            'app.footer_copyright_text' => null,
        ]);

        $html = (string) view('components.branding-footer')->render();

        $this->assertStringContainsString((string) now()->year, $html);
        $this->assertStringContainsString('AI Business OS', $html);
        $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html);
        $this->assertStringNotContainsStringIgnoringCase('Codeglen', $html);
    }

    public function test_footer_component_renders_the_configured_company_and_wording(): void
    {
        config([
            'app.name' => 'AI Business OS',
            'app.footer_company_name' => 'Acme Inc',
            'app.footer_copyright_text' => 'All rights reserved',
        ]);

        $html = (string) view('components.branding-footer')->render();

        $this->assertStringContainsString((string) now()->year, $html);
        $this->assertStringContainsString('Acme Inc', $html);
        $this->assertStringContainsString('All rights reserved', $html);
    }
}
