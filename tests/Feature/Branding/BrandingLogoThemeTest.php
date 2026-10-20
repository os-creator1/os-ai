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

    private function set(?string $light, ?string $dark): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        config(['app.logo' => $light, 'app.logo_dark' => $dark, 'app.logo_compact' => null]);
    }

    public function test_the_supplied_motiongrove_dark_logo_keeps_its_transparency_and_proportions(): void
    {
        $path = public_path('images/branding/motiongrove-logo-for-dark-backgrounds-fits-800x200.png');

        $this->assertFileExists($path);
        [$w, $h] = getimagesize($path);
        $this->assertSame([800, 142], [$w, $h]);
        $this->assertSame(6, ord(file_get_contents($path, false, null, 25, 1)), 'RGBA, so the transparent background is preserved');
    }

    public function test_an_unconfigured_install_shows_one_brand_in_both_themes(): void
    {
        $this->set(BrandingPresenter::DEFAULT_LIGHT_LOGO, null);

        $html = $this->logo('variant="full" background="light" adaptive');

        $this->assertSame(2, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('#<img src="[^"]*default-logo\.svg"[^>]*class="[^"]*mg-logo-light#', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*default-logo-dark\.svg" alt="" aria-hidden="true"[^>]*class="[^"]*mg-logo-dark#', $html);
        $this->assertStringNotContainsString('motiongrove-logo', $html, 'the placeholder brand is never paired with the MotionGrove dark logo');
        $this->assertStringContainsString('w-100', $html, 'existing sizing classes reach both images');
        $this->assertFileExists(public_path(BrandingPresenter::DEFAULT_DARK_LOGO));
    }

    public function test_motiongrove_branding_switches_between_app_logo_and_app_dark_logo(): void
    {
        $this->set('images/logo/motiongrove-light.png', 'images/branding/motiongrove-logo-for-dark-backgrounds-fits-800x200.png');

        $html = $this->logo('variant="full" background="light" adaptive');

        $this->assertSame(2, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('#<img src="[^"]*motiongrove-light\.png"[^>]*class="[^"]*mg-logo-light#', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*motiongrove-logo-for-dark-backgrounds-fits-800x200\.png"[^>]*class="[^"]*mg-logo-dark#', $html);
    }

    public function test_a_custom_light_logo_without_a_dark_one_is_kept_in_both_themes_and_never_replaced(): void
    {
        $this->set('images/logo/owner-brand.png', null);

        $html = $this->logo('variant="full" background="light" adaptive');

        $this->assertSame(1, substr_count($html, '<img'), 'one image, so both themes show the owner logo');
        $this->assertStringContainsString('owner-brand.png', $html);
        $this->assertStringNotContainsString('motiongrove-logo', $html);
        $this->assertStringNotContainsString('mg-logo-', $html);

        $presenter = new BrandingPresenter();
        $this->assertSame('images/logo/owner-brand.png', $presenter->logo('full', 'dark')['src']);
    }

    public function test_custom_light_and_dark_logos_are_each_used_for_their_theme(): void
    {
        $this->set('images/logo/owner-light.png', 'images/logo/owner-dark.png');

        $html = $this->logo('variant="full" background="light" adaptive');

        $this->assertSame(2, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('#<img src="[^"]*owner-light\.png"[^>]*class="[^"]*mg-logo-light#', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*owner-dark\.png"[^>]*class="[^"]*mg-logo-dark#', $html);
        $this->assertStringNotContainsString('motiongrove-logo', $html);
    }

    public function test_an_owner_dark_logo_beats_the_bundled_one_even_on_the_default_light_logo(): void
    {
        $this->set(BrandingPresenter::DEFAULT_LIGHT_LOGO, 'images/logo/owner-dark.png');

        $this->assertSame('images/logo/owner-dark.png', (new BrandingPresenter())->logo('full', 'dark')['src']);
    }

    public function test_compact_and_non_adaptive_logos_are_unchanged(): void
    {
        $this->set(BrandingPresenter::DEFAULT_LIGHT_LOGO, null);

        foreach (['variant="full" background="light"', 'variant="compact" background="light" adaptive'] as $attrs) {
            $html = $this->logo($attrs);
            $this->assertSame(1, substr_count($html, '<img'), $attrs);
            $this->assertStringNotContainsString('mg-logo-', $html, $attrs);
        }
        $this->assertSame('images/branding/default-logo.svg', (new BrandingPresenter())->logo('compact', 'dark')['src']);
    }

    public function test_the_theme_css_keys_off_the_appearance_class_only(): void
    {
        $css = view('panels.appearance-boot')->render();

        $this->assertStringContainsString('html.dark-layout .mg-logo-light{display:none}', $css);
        $this->assertStringContainsString('html.dark-layout .mg-logo-dark{display:inline}', $css);
        $this->assertStringNotContainsString('content:url', $css);
    }
}
