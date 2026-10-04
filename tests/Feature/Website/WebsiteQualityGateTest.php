<?php

namespace Tests\Feature\Website;

use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final — basic performance and accessibility gates on every
 * template's published HTML: landmarks, one H1, an unbroken heading order,
 * alt text, accessible control names, no third-party requests, no inline
 * scripts handlers, a lazy-loaded image strategy and a small payload.
 */
class WebsiteQualityGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    /** @return array<string, string> template key => published home HTML */
    private function publishedHomes(): array
    {
        [, $business] = $this->entitledTenant(['name' => 'Luma Photo Booth Co', 'phone' => '3125550188', 'email' => 'hello@lumabooth.test']);
        $starter = app(WebsiteStarterDraftService::class);
        $website = $starter->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->fakeImageUpload('event.png'), 'A guest posing at a wedding');
        $hero = app(\App\Library\Website\Design\WebsiteLookService::class)->setHero($website, $this->fakeImageUpload('hero.png', $this->validPngBytes() . 'h'), 'Guests in the booth');

        $this->homePage($website, ['seo_title' => 'Luma', 'meta_description' => 'Photo booths.', 'sections' => [
            $this->section('hero', ['heading' => 'Photo booths that make your event unforgettable', 'subheading' => 'Mirror, open-air and 360 booths.']),
            $this->section('services', ['heading' => 'Our booths', 'items' => [['name' => 'Open-Air Photo Booth', 'description' => 'A roomy booth.', 'price_label' => null, 'image' => $asset->uid], ['name' => 'Mirror Photo Booth', 'description' => 'A mirror.', 'price_label' => null, 'image' => null]]]),
            $this->section('image_text', ['heading' => 'Made for events', 'body' => 'Our story.', 'image' => $asset->uid]),
            $this->section('testimonials'),
            $this->section('faq'),
            $this->section('cta', ['heading' => 'Ready to check your date?']),
            $this->section('contact_details'),
        ]]);
        $this->subPage($website, 'photo-booth-about', ['title' => 'About']);
        $this->subPage($website, 'service-360-photo-booth', ['title' => '360 Photo Booth']);

        $homes = [];

        foreach (array_keys(WebsiteDesigns::all()) as $templateKey) {
            $starter->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
            $homes[$templateKey] = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();
        }

        @unlink(public_path($asset->path));
        @unlink(public_path($hero->path));

        return $homes;
    }

    public function test_every_template_has_the_landmarks_one_h1_and_an_unbroken_heading_order(): void
    {
        foreach ($this->publishedHomes() as $templateKey => $html) {
            $this->assertStringContainsString('<html lang="en">', $html);
            $this->assertSame(1, substr_count($html, '<h1'), "{$templateKey}: exactly one H1.");
            $this->assertSame(1, substr_count($html, 'aria-label="Main navigation"'), "{$templateKey}: one main navigation landmark.");
            $this->assertStringContainsString('<main class="wd-main" id="wd-main">', $html);
            $this->assertStringContainsString('<a class="wd-skip" href="#wd-main">', $html, "{$templateKey}: a skip link to the main content.");
            $this->assertSame(1, substr_count($html, '<footer class="wd-footer'), "{$templateKey}: one site footer landmark.");

            preg_match_all('/<h([1-6])[ >]/', $html, $levels);
            $previous = 0;
            foreach ($levels[1] as $level) {
                $this->assertLessThanOrEqual($previous + 1, (int) $level, "{$templateKey}: heading order jumps from h{$previous} to h{$level}.");
                $previous = (int) $level;
            }
            $this->assertSame('1', $levels[1][0], "{$templateKey}: the first heading is the H1.");
        }
    }

    public function test_every_image_has_alt_text_and_every_control_has_an_accessible_name(): void
    {
        foreach ($this->publishedHomes() as $templateKey => $html) {
            preg_match_all('/<img\b[^>]*>/', $html, $images);
            $this->assertNotEmpty($images[0]);
            foreach ($images[0] as $image) {
                $this->assertStringContainsString(' alt="', $image, "{$templateKey}: every image carries alt text: {$image}");
            }

            preg_match_all('/<button\b[^>]*>(.*?)<\/button>/s', $html, $buttons, PREG_SET_ORDER);
            foreach ($buttons as [$whole, $inner]) {
                $named = trim(strip_tags($inner)) !== '' || str_contains($whole, 'aria-label="');
                $this->assertTrue($named, "{$templateKey}: a button without a name: {$whole}");
            }

            // Dropdown toggles announce their state.
            $this->assertStringContainsString('aria-expanded="false"', $html);
            $this->assertStringContainsString('aria-controls="wd-nav"', $html);
        }
    }

    public function test_no_third_party_request_no_inline_handler_and_no_script_url(): void
    {
        $ownHost = parse_url(route('public.website.home', 'x'), PHP_URL_HOST);

        foreach ($this->publishedHomes() as $templateKey => $html) {
            // Resources the page LOADS (stylesheets, scripts, images, frames) — not the owner's own links to elsewhere.
            preg_match_all('/<(?:link|script|img|iframe|source)\b[^>]*?(?:src|href)="(https?:\/\/[^"]+)"/', $html, $urls);
            foreach ($urls[1] as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                $this->assertSame($ownHost, $host, "{$templateKey}: {$url} must be served from this site (no third-party request, no web font CDN).");
            }

            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $html, "{$templateKey}: no inline event handlers.");
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('@import', $html);
        }
    }

    public function test_the_hero_image_is_eager_and_the_rest_are_lazy_and_the_payload_is_small(): void
    {
        foreach ($this->publishedHomes() as $templateKey => $html) {
            preg_match_all('/<img\b[^>]*>/', $html, $images);

            foreach ($images[0] as $image) {
                if (str_contains($image, 'wd-hero') || str_contains($image, 'fetchpriority="high"')) {
                    $this->assertStringNotContainsString('loading="lazy"', $image, "{$templateKey}: the hero image is never lazy (it is the LCP).");
                } elseif (! str_contains($image, 'wd-logo')) {
                    $this->assertStringContainsString('loading="lazy"', $image, "{$templateKey}: below-the-fold images are lazy: {$image}");
                }
            }

            $this->assertLessThan(60 * 1024, strlen($html), "{$templateKey}: the page HTML stays small.");
        }

        $this->assertLessThan(75 * 1024, filesize(public_path('css/website-design.css')), 'One design stylesheet, kept small.');
        $this->assertLessThan(130 * 1024, filesize(public_path('css/website-design.css')) + filesize(public_path('css/website-public.css')), 'Both stylesheets together stay under 130KB.');
    }

    public function test_the_mobile_menu_and_dropdowns_are_keyboard_reachable_real_controls(): void
    {
        foreach ($this->publishedHomes() as $templateKey => $html) {
            $this->assertStringContainsString('<button type="button" class="wd-menu-toggle"', $html, "{$templateKey}: the mobile menu is a real button.");
            $this->assertStringContainsString("event.key !== 'Escape'", $html, "{$templateKey}: Escape closes menus.");
            $this->assertStringNotContainsString('tabindex="-1"', explode('<main', $html)[0], "{$templateKey}: nothing in the header is removed from the tab order.");
        }
    }
}
