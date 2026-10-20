<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingPresenter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The shell logo shows a light and a dark <img>; html.dark-layout (the user's
 * Light/Dark/System appearance) picks one in CSS. Only the opt-in `adaptive`
 * shell logos change; every other use of the component renders exactly as before.
 */
class BrandingLogoThemeTest extends TestCase
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

    private function logo(string $attrs): string
    {
        return Blade::render('<x-branding-logo ' . $attrs . ' class="w-100" />');
    }

    public function test_the_bundled_dark_logo_is_the_supplied_asset_with_its_transparency_and_proportions(): void
    {
        $path = public_path(config('app.logo_dark'));

        $this->assertFileExists($path);
        [$w, $h] = getimagesize($path);
        $this->assertSame([800, 142], [$w, $h]);
        $this->assertSame(6, ord(file_get_contents($path, false, null, 25, 1)), 'RGBA, so the transparent background is preserved');
    }

    public function test_adaptive_shell_logo_renders_light_then_dark_images(): void
    {
        config(['app.logo' => 'images/branding/light.png', 'app.logo_dark' => 'images/branding/dark.png']);

        $html = $this->logo('variant="full" background="light" adaptive');

        $this->assertSame(2, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('#<img src="[^"]*light\.png"[^>]*class="[^"]*mg-logo-light#', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*dark\.png" alt="" aria-hidden="true"[^>]*class="[^"]*mg-logo-dark#', $html);
        $this->assertStringContainsString('w-100', $html, 'existing sizing classes reach both images');
    }

    public function test_a_non_adaptive_logo_is_unchanged(): void
    {
        config(['app.logo' => 'images/branding/light.png', 'app.logo_dark' => 'images/branding/dark.png']);

        foreach (['variant="full" background="light"', 'variant="full" background="dark"', 'variant="compact" background="light" adaptive'] as $attrs) {
            $this->assertSame(1, substr_count($this->logo($attrs), '<img'), $attrs);
        }
        $this->assertStringNotContainsString('mg-logo-', $this->logo('variant="full" background="light"'));
    }

    public function test_without_a_distinct_dark_logo_the_single_light_logo_is_kept(): void
    {
        foreach ([null, '', 'images/branding/light.png'] as $dark) {
            Cache::forget(BrandingPresenter::CACHE_KEY);
            config(['app.logo' => 'images/branding/light.png', 'app.logo_dark' => $dark]);

            $html = $this->logo('variant="full" background="light" adaptive');

            $this->assertSame(1, substr_count($html, '<img'), var_export($dark, true));
            $this->assertStringNotContainsString('mg-logo-', $html);
        }
    }

    public function test_the_theme_css_keys_off_the_appearance_class_only(): void
    {
        $css = view('panels.appearance-boot')->render();

        $this->assertStringContainsString('html.dark-layout .mg-logo-light{display:none}', $css);
        $this->assertStringContainsString('html.dark-layout .mg-logo-dark{display:inline}', $css);
        $this->assertStringNotContainsString('content:url', $css);
    }
}
