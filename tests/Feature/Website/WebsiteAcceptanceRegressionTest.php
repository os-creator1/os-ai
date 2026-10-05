<?php

namespace Tests\Feature\Website;

use App\Library\Website\Design\PhoneDisplay;
use App\Library\Website\GuidedGeneration\MediaBindingService;
use App\Library\Website\WebsiteHealthChecker;
use App\Library\Website\WebsitePublisher;
use App\Library\Catalog\CatalogItemManager;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Regression tests for the defects the Website V1 full-site acceptance bot found
 * in the real pipeline output (see docs/automation/WEBSITE-V1-FULL-SITE-ACCEPTANCE.md):
 *
 *   1. site-relative links ("/services") written into a section were left as-is, so on
 *      Preview and on the platform path they pointed outside the site (404);
 *   2. the Gallery / Backdrops section was appended AFTER the closing contact block;
 *   3. a package's included features rendered as a run-on paragraph, not a list;
 *   4. a phone number rendered as a bare run of digits;
 *   5. every generated page is hidden from search by default, yet Studio Health said
 *      nothing and the only remedy was editing each page.
 */
class WebsiteAcceptanceRegressionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function website(array $business = []): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant($business);

        return [$customer, $business, $workspace, $this->createWebsite($business)];
    }

    private function createAsset(Website $website): WebsiteAsset
    {
        return WebsiteAsset::create(['website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/' . $website->uid . '/' . uniqid('', true) . '.png', 'mime_type' => 'image/png', 'size' => 1024]);
    }

    // ------------------------------------------------ 1. site-relative links

    public function test_a_site_relative_link_in_a_section_resolves_to_that_pages_real_address_on_every_surface(): void
    {
        [$customer, $business, $workspace, $website] = $this->website();
        $this->homePage($website, ['sections' => [
            ['type' => 'hero', 'data' => ['heading' => 'Welcome', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]],
            ['type' => 'cta', 'data' => ['heading' => 'Learn more', 'body' => null, 'buttons' => [['label' => 'Read about us', 'url' => '/about'], ['label' => 'Dead end', 'url' => '/no-such-page']]]],
            ['type' => 'cta', 'data' => ['heading' => 'Back to the start', 'body' => null, 'buttons' => [['label' => 'Home page', 'url' => '/']]]],
        ]]);
        $about = $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website = $website->fresh();

        $platformAbout = route('public.website.page', [$website->public_id, 'about']);
        $platformHome = route('public.website.home', $website->public_id);

        $html = $this->get($platformHome)->assertOk()->getContent();
        $this->assertStringContainsString('href="' . $platformAbout . '"', $html, 'A /about link must point at this site\'s About page.');
        $this->assertStringContainsString('href="' . $platformHome . '"', $html, '"/" is this site\'s home.');
        $this->assertStringNotContainsString('href="/about"', $html);
        $this->assertStringNotContainsString('/no-such-page', $html, 'A link to a page that does not exist is never rendered.');

        // The same content in the owner's Preview points inside Preview.
        $this->authenticateAsCustomer($customer);
        $preview = $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $website->pages()->where('is_home', true)->value('uid')]))->assertOk()->getContent();
        $previewAbout = route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $about->uid]);
        $this->assertStringContainsString('href="' . $previewAbout . '"', $preview);
        $this->assertStringNotContainsString('href="/about"', $preview);
        $this->assertStringNotContainsString('/no-such-page', $preview);
    }

    public function test_absolute_tel_and_mailto_links_are_left_untouched(): void
    {
        $resolver = app(\App\Library\Website\Design\WebsiteCtaResolver::class);
        $pages = ['' => 'https://x.test/', 'about' => 'https://x.test/about'];

        $this->assertSame('https://elsewhere.test/a', $resolver->resolveLink('https://elsewhere.test/a', $pages));
        $this->assertSame('tel:+13125550147', $resolver->resolveLink('tel:+13125550147', $pages));
        $this->assertSame('mailto:a@b.test', $resolver->resolveLink('mailto:a@b.test', $pages));
        $this->assertSame('https://x.test/about', $resolver->resolveLink('/about', $pages));
        $this->assertSame('https://x.test/', $resolver->resolveLink('/', $pages));
        $this->assertSame('', $resolver->resolveLink('/missing', $pages));
        $this->assertSame('', $resolver->resolveLink('//evil.test/x', $pages), 'A protocol-relative URL points off the site and is never rendered.');
    }

    // ------------------------------------------------- 2. section order

    public function test_the_gallery_and_backdrop_sections_sit_before_the_closing_contact_block(): void
    {
        [, $business, , $website] = $this->website();
        $this->createAsset($website);
        $this->createAsset($website);

        $page = [
            'page_key' => 'gallery', 'page_type' => 'gallery', 'is_home' => false, 'slug' => 'gallery', 'title' => 'Gallery', 'seo_title' => null, 'meta_description' => null,
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Gallery']],
                ['type' => 'text', 'data' => ['heading' => 'Our work', 'body' => 'Real events.']],
                ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => true]],
            ],
        ];

        $types = collect(app(MediaBindingService::class)->bind($website, [$page])['pages'][0]['sections'])->pluck('type')->all();

        $this->assertSame(['hero', 'text', 'gallery', 'contact_details'], $types);
    }

    public function test_a_gallery_page_with_nothing_after_its_copy_still_gets_the_gallery_last(): void
    {
        [, , , $website] = $this->website();
        $this->createAsset($website);

        $page = ['page_key' => 'gallery', 'page_type' => 'gallery', 'is_home' => false, 'slug' => 'gallery', 'title' => 'Gallery', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Gallery']]]];

        $types = collect(app(MediaBindingService::class)->bind($website, [$page])['pages'][0]['sections'])->pluck('type')->all();

        $this->assertSame(['hero', 'gallery'], $types);
    }

    public function test_the_owners_chosen_hero_image_is_the_home_hero_even_when_gallery_photos_exist(): void
    {
        [, , , $website] = $this->website();
        $galleryPhoto = $this->createAsset($website);
        $hero = WebsiteAsset::create(['website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/' . $website->uid . '/hero.png', 'mime_type' => 'image/png', 'size' => 1024, 'purpose' => 'hero']);
        $home = ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]]];

        // No owner hero: the gallery photo (the pre-existing rule) is the home hero.
        $without = app(MediaBindingService::class)->bind($website->fresh(), [$home]);
        $this->assertSame($galleryPhoto->uid, $without['pages'][0]['sections'][0]['data']['background_image']);

        // The owner chose a Hero image on Review: that, and not a gallery photo, is the home hero.
        $website->update(['theme' => ['hero_asset_uid' => $hero->uid]]);
        $with = app(MediaBindingService::class)->bind($website->fresh(), [$home]);
        $this->assertSame($hero->uid, $with['pages'][0]['sections'][0]['data']['background_image']);

        // Someone else's asset uid in the theme is ignored (it is not this Website's hero asset).
        $website->update(['theme' => ['hero_asset_uid' => 'not-a-real-asset']]);
        $stray = app(MediaBindingService::class)->bind($website->fresh(), [$home]);
        $this->assertSame($galleryPhoto->uid, $stray['pages'][0]['sections'][0]['data']['background_image']);
    }

    // --------------------------------------------------- 3. package features

    public function test_a_packages_included_features_render_as_a_list_not_a_run_on_paragraph(): void
    {
        [, $business, , $website] = $this->website();
        $manager = app(CatalogItemManager::class);
        $essential = $manager->create($business, ['type' => 'package', 'name' => 'Essential', 'description' => "Everything for a small party.\n\n- 2 hours of booth time\n- Unlimited digital captures", 'price_minor' => 69900, 'currency_code' => 'USD']);
        $plain = $manager->create($business, ['type' => 'package', 'name' => 'Plain', 'description' => 'Only a sentence, no features.', 'price_minor' => 39900, 'currency_code' => 'USD']);
        $this->homePage($website, ['sections' => [
            ['type' => 'hero', 'data' => ['heading' => 'Packages', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]],
            ['type' => 'services', 'data' => ['heading' => 'Choose your package', 'items' => [
                ['catalog_item_uid' => $essential->uid, 'name' => 'Essential', 'description' => "Everything for a small party.

- 2 hours of booth time
- Unlimited digital captures", 'price_label' => 'x'],
                ['catalog_item_uid' => $plain->uid, 'name' => 'Plain', 'description' => 'Only a sentence, no features.', 'price_label' => 'x'],
            ]]],
        ]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $html = $this->get(route('public.website.home', $website->fresh()->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('<p>Everything for a small party.</p>', $html);
        $this->assertMatchesRegularExpression('#<ul class="website-service-features">\s*<li>2 hours of booth time</li>\s*<li>Unlimited digital captures</li>\s*</ul>#', $html);
        $this->assertStringNotContainsString('- 2 hours', $html, 'No raw "- " bullet text.');
        $this->assertSame(1, substr_count($html, 'website-service-features'), 'A package with no features gets no empty list.');
    }

    // ------------------------------------- 3b. hero image fills its section

    public function test_the_hero_background_image_rule_outranks_the_generic_template_image_rule(): void
    {
        // Found in the browser at 390px: `.wd img { height: auto }` (specificity 0,1,1) beat the bare
        // `.wd-hero-bg { height: 100% }` (0,1,0), so on a phone the hero photo covered only its natural
        // aspect-ratio strip and the rest of the hero section was bare background colour.
        $css = (string) file_get_contents(base_path('public/css/website-design.css'));

        $this->assertMatchesRegularExpression('/^\.wd \.wd-hero-bg \{[^}]*\bheight: 100%;/m', $css, 'The hero image must be sized by a rule at least as specific as ".wd img".');
        $this->assertDoesNotMatchRegularExpression('/^\.wd-hero-bg \{/m', $css, 'A bare .wd-hero-bg rule loses to ".wd img".');
    }

    // ------------------------------------------------------ 4. phone numbers

    public function test_phone_display_formats_north_american_numbers_and_leaves_anything_else_alone(): void
    {
        $this->assertSame('(312) 555-0147', PhoneDisplay::format('3125550147'));
        $this->assertSame('(312) 555-0147', PhoneDisplay::format('(312) 555-0147'));
        $this->assertSame('(312) 555-0147', PhoneDisplay::format('312.555.0147'));
        $this->assertSame('+1 (312) 555-0147', PhoneDisplay::format('+13125550147'));
        $this->assertSame('+44 20 7946 0958', PhoneDisplay::format('+44 20 7946 0958'), 'Never guess at a non-NANP number.');
        $this->assertSame('ext. 12', PhoneDisplay::format('ext. 12'));
        $this->assertSame('', PhoneDisplay::format(null));
        $this->assertSame('+13125550147', PhoneDisplay::dial('+1 (312) 555-0147'));
        $this->assertSame('3125550147', PhoneDisplay::dial('312-555-0147'));
    }

    public function test_the_public_site_shows_a_formatted_phone_and_dials_the_digits(): void
    {
        [, , , $website] = $this->website(['phone' => '3125550147']);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->homePage($website, ['sections' => [
            ['type' => 'hero', 'data' => ['heading' => 'Welcome', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]],
            ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => false, 'show_address' => false]],
        ]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $html = $this->get(route('public.website.home', $website->fresh()->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="tel:3125550147">(312) 555-0147</a>', $html);
        $this->assertDoesNotMatchRegularExpression('#>\s*3125550147\s*<#', $html);
    }

    // ------------------------------------------- 5. hidden-from-search status

    public function test_health_tells_the_owner_how_many_pages_are_hidden_from_search_and_the_one_action_fixes_it(): void
    {
        [$customer, $business, $workspace, $website] = $this->website();
        $this->homePage($website, ['noindex' => true]);
        $this->subPage($website, 'about', ['noindex' => true]);
        $this->subPage($website, 'services', ['noindex' => false, 'sort_order' => 2]);
        $this->authenticateAsCustomer($customer);

        $check = fn () => collect(app(WebsiteHealthChecker::class)->check($website->fresh(), ['pages' => '/pages']))->get('checks');
        $indexing = collect($check())->firstWhere('key', 'indexing');

        $this->assertSame('warn', $indexing['status']);
        $this->assertStringContainsString('2 of 3 pages are hidden from search engines', $indexing['detail']);
        $this->assertSame('/pages', $indexing['action']['url']);

        $pages = $this->get(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]))->assertOk();
        $pages->assertSee('2 of 3 pages are hidden from search engines');
        $pages->assertSee('data-testid="allow-indexing"', false);

        $this->post(route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]))
            ->assertSessionHas('message', '2 pages can now be found in search. Publish to update your live website.');

        $this->assertSame(0, $website->pages()->where('noindex', true)->count());
        $this->assertSame('ok', collect($check())->firstWhere('key', 'indexing')['status']);

        // Idempotent: a second click changes nothing and says so.
        $this->post(route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspace->uid, $business->uid]))
            ->assertSessionHas('message', 'Every page can already be found in search.');

        // Nothing goes live by itself: the published site is only changed by Publish.
        $this->assertNull($website->fresh()->published_revision_id);
    }

    public function test_the_search_visibility_action_cannot_touch_another_customers_website(): void
    {
        [, $business, $workspace, $website] = $this->website();
        $this->homePage($website, ['noindex' => true]);

        [$intruder] = $this->entitledTenant();
        $this->authenticateAsCustomer($intruder);

        $this->post(route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspace->uid, $business->uid]))->assertNotFound();

        $this->assertSame(1, Website::find($website->id)->pages()->where('noindex', true)->count());
    }
}
