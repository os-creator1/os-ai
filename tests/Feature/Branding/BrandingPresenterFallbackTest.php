<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingPresenter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §6.4/§9 item 2. Proves the
 * exact fallback resolution order: the owner-configured value for the
 * exact variant/background combination, else the full/light logo, else
 * null (never a broken <img>); favicon() never empty;
 * illustration()/footerCopyrightLine() fall back correctly.
 */
class BrandingPresenterFallbackTest extends TestCase
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

    public function test_logo_returns_the_bundled_default_when_unconfigured(): void
    {
        config(['app.logo' => 'images/branding/default-logo.svg', 'app.logo_compact' => null, 'app.logo_dark' => null]);

        $presenter = new BrandingPresenter();
        $result = $presenter->logo('full', 'light');

        $this->assertSame('images/branding/default-logo.svg', $result['src']);
        $this->assertFalse($result['isFallback']);
    }

    public function test_logo_falls_back_to_full_light_when_the_exact_variant_is_unset(): void
    {
        config([
            'app.logo' => 'images/branding/owner-full.png',
            'app.logo_compact' => null,
            'app.logo_dark' => null,
        ]);

        $presenter = new BrandingPresenter();
        $result = $presenter->logo('compact', 'light');

        $this->assertSame('images/branding/owner-full.png', $result['src']);
    }

    public function test_logo_returns_the_exact_configured_variant_when_set(): void
    {
        config([
            'app.logo' => 'images/branding/owner-full.png',
            'app.logo_compact' => 'images/branding/owner-compact.png',
            'app.logo_dark' => null,
        ]);

        $presenter = new BrandingPresenter();
        $result = $presenter->logo('compact', 'light');

        $this->assertSame('images/branding/owner-compact.png', $result['src']);
    }

    public function test_logo_returns_null_and_marks_fallback_when_nothing_at_all_is_configured(): void
    {
        config(['app.logo' => null, 'app.logo_compact' => null, 'app.logo_dark' => null]);

        $presenter = new BrandingPresenter();
        $result = $presenter->logo('full', 'light');

        $this->assertNull($result['src']);
        $this->assertTrue($result['isFallback']);
    }

    public function test_favicon_is_never_empty(): void
    {
        config(['app.favicon' => 'images/branding/default-favicon.svg']);

        $presenter = new BrandingPresenter();

        $this->assertNotEmpty($presenter->favicon());
    }

    public function test_illustration_falls_back_to_the_bundled_vuexy_file_per_surface(): void
    {
        config(['app.auth_illustration' => null, 'app.installer_illustration' => null]);

        $presenter = new BrandingPresenter();

        $auth = $presenter->illustration('auth');
        $installer = $presenter->illustration('installer');

        $this->assertSame('images/pages/login-v2.svg', $auth['src']);
        $this->assertSame('images/pages/create-account.svg', $installer['src']);
    }

    public function test_illustration_returns_the_owner_configured_value_when_set(): void
    {
        config(['app.auth_illustration' => 'images/branding/owner-auth.png']);

        $presenter = new BrandingPresenter();

        $this->assertSame('images/branding/owner-auth.png', $presenter->illustration('auth')['src']);
    }

    public function test_footer_copyright_line_contains_the_current_year_and_omits_wording_when_unset(): void
    {
        config(['app.footer_company_name' => null, 'app.name' => 'AI Business OS', 'app.footer_copyright_text' => null]);

        $presenter = new BrandingPresenter();
        $line = $presenter->footerCopyrightLine();

        $this->assertStringContainsString((string) now()->year, $line);
        $this->assertStringContainsString('AI Business OS', $line);
        $this->assertStringNotContainsString('  ', $line);
    }

    public function test_footer_copyright_line_includes_configured_wording(): void
    {
        config(['app.footer_company_name' => 'Acme Inc', 'app.footer_copyright_text' => 'All rights reserved']);

        $presenter = new BrandingPresenter();
        $line = $presenter->footerCopyrightLine();

        $this->assertStringContainsString((string) now()->year, $line);
        $this->assertStringContainsString('Acme Inc', $line);
        $this->assertStringContainsString('All rights reserved', $line);
    }
}
