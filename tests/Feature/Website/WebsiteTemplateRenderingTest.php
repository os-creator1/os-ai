<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\Design\BrandColors;
use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\Design\WebsiteLookService;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsitePage;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final — the template-driven public renderer. ONE business
 * ("Luma Photo Booth Co", the shared dataset) rendered in each of the four
 * templates: the content is identical, the structure is the template's own.
 * Also: logo, brand colour, hero image, compact navigation, footer reach,
 * and "switching a template changes layout, never content" with the
 * published site frozen until the owner publishes again.
 */
class WebsiteTemplateRenderingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    /**
     * @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: Website}
     */
    private function lumaWebsite(string $templateKey = 'photo_booth_modern'): array
    {
        [$customer, $business] = $this->entitledTenant(['name' => 'Luma Photo Booth Co', 'phone' => '3125550188', 'email' => 'hello@lumabooth.test']);

        foreach (['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth'] as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i]);
        }
        foreach ([['Essential Package', 59900, false], ['Signature Package', 84900, true]] as $i => [$name, $price, $featured]) {
            CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => $name, 'price_minor' => $price, 'currency_code' => 'USD', 'position' => $i, 'featured' => $featured, 'created_by_user_id' => $customer->user_id]);
        }

        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail($templateKey));

        return [$customer, $business, $website];
    }

    private function publish(Website $website): void
    {
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
    }

    /** The text a visitor reads (the accent word is its own <span>, so compare text, not markup). */
    private function visibleText(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    /** @return array<int, string> the home page's section bands, in rendered order */
    private function renderedSections(string $html): array
    {
        preg_match_all('/data-section="([a-z_]+)"/', $html, $matches);

        return $matches[1];
    }

    private function richHome(Website $website): void
    {
        $this->homePage($website, ['title' => 'Luma Photo Booth Co', 'seo_title' => 'Luma Photo Booth Co', 'meta_description' => 'Photo booths in Chicago.', 'sections' => [
            $this->section('faq'),
            $this->section('contact_details'),
            $this->section('hero', ['heading' => 'Photo booths that make your event unforgettable', 'subheading' => 'Mirror, open-air and 360 booths.']),
            $this->section('testimonials'),
            $this->section('services', ['heading' => 'Our booths', 'items' => [['name' => 'Open-Air Photo Booth', 'description' => 'A roomy booth.', 'price_label' => null, 'image' => null]]]),
            $this->section('cta', ['heading' => 'Ready to check your date?']),
        ]]);
    }

    public function test_the_same_business_renders_four_structurally_different_websites_with_identical_content(): void
    {
        [, $business, $website] = $this->lumaWebsite();
        $this->richHome($website);
        $this->subPage($website, 'packages', ['title' => 'Packages', 'sections' => [$this->section('hero', ['heading' => 'Packages']), ['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => CatalogItem::where('business_id', $business->id)->orderBy('position')->get()->map(fn ($item) => ['catalog_item_uid' => $item->uid, 'name' => $item->name, 'description' => null, 'price_label' => null, 'image' => null])->all()]]]]);

        $starter = app(WebsiteStarterDraftService::class);
        $structure = [];
        $orders = [];

        foreach (WebsiteDesigns::all() as $templateKey => $design) {
            $starter->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            $this->publish($website);

            $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

            $this->assertStringContainsString('wd wd-' . $design->key, $html, 'The body carries the template\'s own design class.');
            $this->assertStringContainsString('wd-header-' . $design->variant('header'), $html);
            $this->assertStringContainsString('wd-hero-' . $design->variant('hero'), $html);
            $this->assertStringContainsString('wd-footer-' . $design->variant('footer'), $html);
            $this->assertStringNotContainsString('website-preview-banner', $html, 'A published page never shows editor chrome.');
            $this->assertStringNotContainsString('phpdebugbar', $html);

            // Identical content in every template.
            foreach (['Luma Photo Booth Co', 'Photo booths that make your event unforgettable', 'Open-Air Photo Booth', 'Are you open weekends?'] as $text) {
                $this->assertStringContainsString($text, $this->visibleText($html), "{$text} must appear in {$design->label}.");
            }

            $structure[$templateKey] = $design->variant('header') . '|' . $design->variant('hero') . '|' . $design->variant('footer');
            $orders[$templateKey] = implode(',', $this->renderedSections($html));

            // The template owns the order of the home page's sections.
            $this->assertSame(
                array_column($design->orderHomeSections([['type' => 'faq'], ['type' => 'contact_details'], ['type' => 'hero'], ['type' => 'testimonials'], ['type' => 'services'], ['type' => 'cta']]), 'type'),
                $this->renderedSections($html),
            );
        }

        $this->assertCount(4, array_unique($structure), 'Header, hero and footer layouts differ per template.');
        $this->assertGreaterThan(1, count(array_unique($orders)), 'Home section order differs between templates.');
    }

    public function test_the_packages_page_shows_live_canonical_packages_in_every_template_with_the_featured_one_emphasised(): void
    {
        [, $business, $website] = $this->lumaWebsite();
        $this->richHome($website);
        $this->subPage($website, 'packages', ['title' => 'Packages', 'sections' => [['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => CatalogItem::where('business_id', $business->id)->orderBy('position')->get()->map(fn ($item) => ['catalog_item_uid' => $item->uid, 'name' => 'stale ' . $item->name, 'description' => null, 'price_label' => 'stale', 'image' => null])->all()]]]]);

        foreach (array_keys(WebsiteDesigns::all()) as $templateKey) {
            app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            $this->publish($website);

            $html = $this->get(route('public.website.page', [$website->public_id, 'packages']))->assertOk()->getContent();

            $this->assertStringContainsString('Signature Package', $html);
            $this->assertStringContainsString('USD 849.00', $html, 'The price is the live catalog price, never the stale copy.');
            $this->assertStringNotContainsString('stale', $html);
            $this->assertStringContainsString('wd-card-featured', $html, 'The featured package is emphasised.');
            $this->assertSame(1, substr_count($html, 'wd-card-featured'));
            $this->assertStringContainsString('data-testid="packages-section"', $html);
        }
    }

    public function test_switching_a_template_changes_layout_not_content_and_the_published_site_is_frozen_until_the_owner_publishes(): void
    {
        [, , $website] = $this->lumaWebsite('photo_booth_modern');
        $this->richHome($website);
        $this->publish($website);
        $url = route('public.website.home', $website->public_id);

        $before = $this->get($url)->getContent();
        $this->assertStringContainsString('wd wd-modern', $before);

        app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail('photo_booth_luxury'));

        $this->assertSame('photo_booth_luxury', $website->fresh()->template_key);
        $this->assertSame(1, $website->fresh()->pages()->count(), 'No page is regenerated or removed.');
        $this->assertStringContainsString('wd wd-modern', $this->get($url)->getContent(), 'The live site keeps its frozen design until publish.');

        $this->publish($website);
        $after = $this->get($url)->getContent();

        $this->assertStringContainsString('wd wd-luxury', $after);
        $this->assertStringNotContainsString('wd wd-modern', $after);
        $this->assertStringContainsString('Photo booths that make your event unforgettable', $this->visibleText($after));
    }

    public function test_the_owners_logo_brand_colour_and_hero_image_flow_through_to_the_published_site(): void
    {
        [, , $website] = $this->lumaWebsite();
        $this->richHome($website);

        $look = app(WebsiteLookService::class);
        $logo = $look->setLogo($website, $this->fakeImageUpload('logo.png'), 'Luma logo');
        // A different byte string: the same bytes would share one content-hashed file (and URL) with the logo.
        $hero = $look->setHero($website->fresh(), $this->fakeImageUpload('hero.png', $this->validPngBytes() . 'hero'), 'Guests in the booth');
        $look->setBrandColor($website->fresh(), '#ff5500');

        $this->assertSame(\App\Enums\Website\WebsiteAssetPurpose::Logo, $logo->purpose);
        $this->assertSame(\App\Enums\Website\WebsiteAssetPurpose::Hero, $hero->purpose);

        $this->publish($website);
        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringContainsString($logo->url(), $html, 'The logo is shown in the published header.');
        $this->assertStringContainsString('alt="Luma logo"', $html);
        $this->assertStringContainsString($hero->url(), $html, 'The hero image is used by the hero (a full-bleed background in Template 1).');
        $this->assertStringContainsString(BrandColors::inlineStyle('#ff5500'), $html);

        // A template whose hero frames the photo as an <img> carries the owner's alt text.
        app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail('photo_booth_luxury'));
        $this->publish($website);
        $luxury = $this->get(route('public.website.home', $website->public_id))->getContent();
        $this->assertStringContainsString('alt="Guests in the booth"', $luxury);
        $this->assertStringContainsString($hero->url(), $luxury);

        // Both assets are carried into the immutable revision, and survive a template switch.
        $snapshot = $website->fresh()->publishedRevision->snapshot;
        $this->assertContains($logo->uid, array_column($snapshot['assets'], 'uid'));
        $this->assertContains($hero->uid, array_column($snapshot['assets'], 'uid'));
        $this->assertSame('#ff5500', $snapshot['website']['theme']['brand_color']);

        app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail('photo_booth_conversion'));
        $theme = $website->fresh()->theme;
        $this->assertSame('#ff5500', $theme['brand_color']);
        $this->assertSame($logo->uid, $theme['logo_asset_uid']);
        $this->assertSame($hero->uid, $theme['hero_asset_uid']);
    }

    public function test_a_site_without_a_hero_image_shows_the_templates_designed_hero_never_an_empty_frame(): void
    {
        [, , $website] = $this->lumaWebsite();
        $this->richHome($website);

        foreach (array_keys(WebsiteDesigns::all()) as $templateKey) {
            app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            $this->publish($website);

            $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

            $this->assertStringContainsString('wd-hero-typographic', $html);
            $this->assertStringNotContainsString('wd-hero-media', $html, 'No image, so no image frame is rendered.');
            $this->assertStringNotContainsString('<img', explode('<main', $html)[1] ?? '', 'No placeholder image anywhere in the hero.');
        }
    }

    public function test_navigation_is_compact_and_every_page_stays_reachable_even_with_many_pages(): void
    {
        [, , $website] = $this->lumaWebsite();
        $this->richHome($website);
        $order = 1;
        foreach (['services' => 'Services', 'packages' => 'Packages', 'gallery' => 'Gallery', 'photo-booth-about' => 'About', 'photo-booth-faq' => 'FAQ', 'photo-booth-contact' => 'Contact', 'service-360-photo-booth' => '360 Photo Booth', 'service-mirror-photo-booth' => 'Mirror Photo Booth', 'service-open-air-photo-booth' => 'Open-Air Photo Booth', 'serving-chicago' => 'Serving Chicago', 'serving-evanston' => 'Serving Evanston', 'serving-oak-park' => 'Serving Oak Park', 'red-carpet' => 'Red Carpet'] as $slug => $title) {
            $this->subPage($website, $slug, ['title' => $title, 'sort_order' => $order++]);
        }
        $this->assertSame(14, $website->pages()->count());

        foreach (array_keys(WebsiteDesigns::all()) as $templateKey) {
            app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            $this->publish($website);

            $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();
            $nav = explode('</nav>', explode('id="wd-nav"', $html)[1])[0];

            $topLevel = substr_count($nav, 'class="wd-nav-item');
            $this->assertLessThanOrEqual(7, $topLevel, '14 pages never become 14 header items.');
            $this->assertStringContainsString('data-wd-menu-toggle', $html, 'A real mobile menu exists.');
            $this->assertStringContainsString('aria-label="Main navigation"', $html);

            // Every page is reachable from the header or the footer.
            foreach (WebsitePage::where('website_id', $website->id)->whereNotNull('slug')->pluck('slug') as $slug) {
                $this->assertStringContainsString('/' . $slug . '"', $html, "{$slug} must be linked from the header or footer.");
            }
        }
    }

    public function test_footer_lists_services_and_areas_and_the_owners_contact_details(): void
    {
        [, , $website] = $this->lumaWebsite();
        $this->richHome($website);
        $this->subPage($website, 'service-360-photo-booth', ['title' => '360 Photo Booth', 'sort_order' => 1]);
        $this->subPage($website, 'serving-evanston', ['title' => 'Serving Evanston', 'sort_order' => 2]);
        $this->publish($website);

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();
        $footer = explode('data-testid="site-footer"', $html)[1];

        $this->assertStringContainsString('Services', $footer);
        $this->assertStringContainsString('360 Photo Booth', $footer);
        $this->assertStringContainsString('Areas we serve', $footer);
        $this->assertStringContainsString('Evanston', $footer);
        $this->assertStringContainsString('tel:3125550188', $footer);
        $this->assertStringContainsString('hello@lumabooth.test', $footer);
    }

    public function test_a_legacy_site_without_a_template_keeps_the_original_generic_chrome(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business, ['theme' => ['header_variant' => 'bold', 'primary_color' => '#e85d3f', 'button_style' => 'rounded', 'font' => 'system']]);
        $this->homePage($website);
        $this->publish($website);

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('class="website-header"', $html);
        $this->assertStringNotContainsString('wd-header', $html);
        $this->assertStringNotContainsString('website-design.css', $html);
    }

    public function test_the_hero_and_form_pages_render_with_a_real_form_and_no_debug_chrome(): void
    {
        [, , $website] = $this->lumaWebsite();
        $form = WebsiteForm::create(['website_id' => $website->id, 'business_id' => $website->business_id, 'type' => WebsiteForm::TYPE_QUOTE_REQUEST, 'name' => 'Quote', 'fields' => \App\Library\Website\WebsiteFormPresets::photoBoothQuoteRequest(), 'submit_label' => 'Request a quote']);
        $this->homePage($website);
        $this->subPage($website, 'photo-booth-contact', ['title' => 'Contact', 'sections' => [$this->section('hero', ['heading' => 'Contact']), $this->section('form', ['form_uid' => $form->uid])]]);

        foreach (array_keys(WebsiteDesigns::all()) as $templateKey) {
            app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            $this->publish($website);

            $html = $this->get(route('public.website.page', [$website->public_id, 'photo-booth-contact']))->assertOk()->getContent();

            $this->assertStringContainsString('<form method="POST"', $html);
            $this->assertStringContainsString('Request a quote', $html);
            $this->assertSame(1, substr_count($html, '<h1'), 'One H1 per page.');
        }
    }

    public function test_image_assets_in_a_template_site_keep_lazy_loading_and_alt_text(): void
    {
        [, , $website] = $this->lumaWebsite();
        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->fakeImageUpload('event.png'), 'A guest posing at a wedding');
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('image_text', ['image' => $asset->uid, 'heading' => 'Made for events', 'body' => 'Our story.'])]]);
        $this->publish($website);

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('alt="A guest posing at a wedding"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }
}
