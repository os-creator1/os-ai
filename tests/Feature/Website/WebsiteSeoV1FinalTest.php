<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Seo\WebsiteBreadcrumbStructuredData;
use App\Library\Website\Seo\WebsiteHeadMeta;
use App\Library\Website\Seo\WebsiteRedirectMap;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteSlugRules;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * SEO V1 final — the public-site SEO defects the product-wide audit found:
 * the <head> (title, description, social), one-address-per-page (redirects,
 * trailing slash, strict slug matching), the custom domain's own 404, the
 * indexing intent flags, and the slug authorities.
 */
class WebsiteSeoV1FinalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function liveSite(string $host = 'seo-final.test', array $pages = ['about']): Website
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business, ['name' => 'Luma Booth Co']);
        $this->homePage($website, ['title' => 'Home', 'seo_title' => 'Luma Booth Co', 'meta_description' => 'Photo booth rentals in Chicago.']);

        foreach ($pages as $slug) {
            $this->subPage($website, $slug, ['title' => ucfirst($slug), 'seo_title' => ucfirst($slug).' | Luma Booth Co', 'meta_description' => 'About '.$slug.'.']);
        }

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $this->activeDomain($website, $host);

        return $website->fresh();
    }

    private function rawGet(string $url): \Illuminate\Testing\TestResponse
    {
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create($url, 'GET'));

        return \Illuminate\Testing\TestResponse::fromBaseResponse($response);
    }

    private function activeDomain(Website $website, string $host): void
    {
        $website->domains()->create([
            'domain' => $host,
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ head

    public function test_the_title_never_repeats_the_business_name_and_the_description_tag_is_absent_when_empty(): void
    {
        $this->assertSame('Services | Luma Booth Co', WebsiteHeadMeta::title('Services | Luma Booth Co', 'Services', 'Luma Booth Co'));
        $this->assertSame('Luma Booth Co', WebsiteHeadMeta::title('Luma Booth Co', 'Home', 'Luma Booth Co'));
        $this->assertSame('Gallery | Luma Booth Co', WebsiteHeadMeta::title('', 'Gallery', 'Luma Booth Co'));
        $this->assertSame('Open-air booth rentals | Luma Booth Co', WebsiteHeadMeta::title('Open-air booth rentals', 'Services', 'Luma Booth Co'));

        $website = $this->liveSite();
        $website->pages()->where('slug', 'about')->first(); // published already; add a page with no description and republish
        $this->subPage($website, 'gallery', ['title' => 'Gallery', 'seo_title' => null, 'meta_description' => null]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        Cache::flush();

        $html = $this->get('http://seo-final.test/gallery')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringContainsString('<title>Gallery | Luma Booth Co</title>', $html);
        $this->assertStringNotContainsString('name="description"', $html);
        $this->assertStringNotContainsString('og:description', $html);
        $this->assertStringNotContainsString('content=""', $html);
    }

    public function test_social_tags_are_present_and_og_url_equals_the_canonical(): void
    {
        $this->liveSite();

        $html = $this->get('http://seo-final.test/about')->assertOk()->getContent();

        preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $canonical);
        $this->assertSame('https://seo-final.test/about', $canonical[1] ?? null);
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));

        $this->assertStringContainsString('<meta property="og:url" content="https://seo-final.test/about">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="About | Luma Booth Co">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Luma Booth Co">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        // No owner photo in this fixture: no og:image is invented.
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function test_preview_and_platform_path_have_no_og_url_because_they_have_no_canonical(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:url', $html);
        $this->assertStringContainsString('noindex, follow', $html);
    }

    // -------------------------------------------------------- one address per page

    public function test_a_renamed_page_redirects_permanently_from_its_old_address(): void
    {
        $website = $this->liveSite();
        $about = $website->pages()->where('slug', 'about')->first();

        app(WebsiteDraftPageService::class)->updatePage($website, $about, ['title' => 'About us', 'slug' => 'about-us', 'is_home' => false]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        Cache::flush();

        $this->get('http://seo-final.test/about')->assertStatus(301)->assertRedirect('https://seo-final.test/about-us');
        $this->get('http://seo-final.test/about-us')->assertOk();
        $this->get('http://seo-final.test/never-existed')->assertNotFound();

        // A second rename collapses the chain to the final address.
        $about->refresh();
        app(WebsiteDraftPageService::class)->updatePage($website, $about, ['title' => 'Our team', 'slug' => 'our-team', 'is_home' => false]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        Cache::flush();

        $this->get('http://seo-final.test/about')->assertStatus(301)->assertRedirect('https://seo-final.test/our-team');
        $this->get('http://seo-final.test/about-us')->assertStatus(301)->assertRedirect('https://seo-final.test/our-team');
    }

    public function test_the_platform_path_redirects_a_renamed_page_too(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $about = $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        app(WebsiteDraftPageService::class)->updatePage($website, $about, ['title' => 'About us', 'slug' => 'about-us', 'is_home' => false]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.page', [$website->public_id, 'about']))
            ->assertStatus(301)
            ->assertRedirect(route('public.website.page', [$website->public_id, 'about-us']));
    }

    public function test_a_page_that_never_existed_in_a_revision_gets_no_redirect(): void
    {
        $map = new WebsiteRedirectMap();
        $previous = ['pages' => [['uid' => 'a', 'slug' => 'about', 'is_home' => false], ['uid' => 'h', 'slug' => null, 'is_home' => true]], 'redirects' => []];
        $next = [['uid' => 'a', 'slug' => 'about', 'is_home' => false], ['uid' => 'h', 'slug' => null, 'is_home' => true]];

        $this->assertSame([], $map->compute($previous, $next));
        $this->assertSame([], $map->compute(null, $next));

        // A redirect whose target page was later removed is dropped, never left dangling.
        $withStale = $previous + [];
        $withStale['redirects'] = ['old' => 'gone'];
        $this->assertSame([], $map->compute($withStale, $next));

        // A source that is a live page again is never redirected away.
        $withLive = $previous;
        $withLive['redirects'] = ['about' => ''];
        $this->assertSame([], $map->compute($withLive, $next));
    }

    public function test_a_page_promoted_to_home_leaves_a_redirect_and_the_old_home_gets_a_real_slug(): void
    {
        $website = $this->liveSite('promote.test');
        $about = $website->pages()->where('slug', 'about')->first();
        $oldHome = $website->pages()->where('is_home', true)->first();

        app(WebsiteDraftPageService::class)->updatePage($website, $about, ['title' => 'About', 'is_home' => true]);

        $oldHome->refresh();
        $this->assertFalse($oldHome->is_home);
        $this->assertNotNull($oldHome->slug, 'A demoted homepage must not keep a NULL slug.');
        $this->assertTrue(WebsiteSlugRules::isValid($oldHome->slug));

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        Cache::flush();

        $this->get('http://promote.test/')->assertOk();
        $this->get('http://promote.test/about')->assertStatus(301)->assertRedirect('https://promote.test/');
        $this->get('http://promote.test/'.$oldHome->slug)->assertOk();

        $sitemap = $this->get('http://promote.test/sitemap.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($sitemap, '<loc>https://promote.test/</loc>'), 'Home appears once.');
        $this->assertSame(1, substr_count($sitemap, '<loc>https://promote.test/'.$oldHome->slug.'</loc>'));

        // And the platform path still builds a URL for every page (it used to 500 on a NULL slug).
        // (A fixed platform URL: route() follows the host of the LAST request, which was the custom domain.)
        $this->get('http://localhost/sites/'.$website->public_id)->assertStatus(301)->assertRedirect('https://promote.test/');
    }

    public function test_publish_refuses_a_non_home_page_without_a_slug(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $page = $this->subPage($website, 'about');
        \Illuminate\Support\Facades\DB::table('website_pages')->where('id', $page->id)->update(['slug' => null]);

        try {
            app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
            $this->fail('A page with no slug must block publishing.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('web address', $e->errors()['website'][0]);
        }
    }

    public function test_real_public_directories_cannot_be_page_slugs(): void
    {
        foreach (['images', 'css', 'js', 'fonts', 'vendors', 'main', 'robots', 'sitemap'] as $slug) {
            $this->assertFalse(WebsiteSlugRules::isValid($slug), $slug.' must be reserved');
        }

        $this->assertTrue(WebsiteSlugRules::isValid('service-photo-booth-rental'));
    }

    public function test_a_trailing_slash_is_a_permanent_redirect_and_a_numeric_look_alike_does_not_match(): void
    {
        $this->liveSite('slash.test', ['about', '10']);

        // The test client trims a trailing slash from the URL itself, so the request is built by hand.
        $this->rawGet('http://slash.test/about/')->assertStatus(301)->assertRedirect('https://slash.test/about');
        $this->rawGet('http://slash.test/about/?utm=x')->assertStatus(301)->assertRedirect('https://slash.test/about?utm=x');
        $this->get('http://slash.test/10')->assertOk();
        $this->get('http://slash.test/010')->assertNotFound();
        $this->get('http://slash.test/1e1')->assertNotFound();
    }

    public function test_the_custom_domain_404_is_the_customers_page_not_the_platforms(): void
    {
        $this->liveSite('four04.test');

        $response = $this->get('http://four04.test/nothing-here');

        $response->assertNotFound();
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');
        $html = $response->getContent();
        $this->assertStringContainsString('We couldn\'t find that page', $html);
        $this->assertStringContainsString('href="https://four04.test/"', $html);
        $this->assertStringNotContainsString('/login', $html, 'The platform login is a route a custom domain refuses.');
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_a_maintenance_window_is_not_reported_as_a_missing_page(): void
    {
        Route::get('/__seo_v1_maintenance_probe', fn () => abort(503, 'Down', ['Retry-After' => '120']));

        $this->get('/__seo_v1_maintenance_probe')->assertStatus(503)->assertHeader('Retry-After', '120');
    }

    // ------------------------------------------------------------- breadcrumbs

    public function test_the_visible_breadcrumb_and_the_json_ld_trail_are_the_same_list(): void
    {
        $pages = [
            ['uid' => 'h', 'slug' => null, 'is_home' => true, 'title' => 'Home', 'url' => 'https://x.test/'],
            ['uid' => 's', 'slug' => 'services', 'is_home' => false, 'title' => 'Our long headline about services', 'url' => 'https://x.test/services'],
            ['uid' => 'p', 'slug' => 'service-open-air', 'is_home' => false, 'title' => 'Open-air booth', 'url' => 'https://x.test/service-open-air'],
            ['uid' => 'a', 'slug' => 'serving-austin', 'is_home' => false, 'title' => 'Serving Austin', 'url' => 'https://x.test/serving-austin'],
        ];

        $service = WebsiteBreadcrumbStructuredData::trail($pages[2], $pages);
        $this->assertSame([['name' => 'Home', 'url' => 'https://x.test/'], ['name' => 'Services', 'url' => 'https://x.test/services'], ['name' => 'Open-air booth', 'url' => 'https://x.test/service-open-air']], $service);

        // Location pages have no hub page: Home > Austin (the label the menu uses), never "Serving Austin".
        $area = WebsiteBreadcrumbStructuredData::trail($pages[3], $pages);
        $this->assertSame([['name' => 'Home', 'url' => 'https://x.test/'], ['name' => 'Austin', 'url' => 'https://x.test/serving-austin']], $area);

        $this->assertSame([], WebsiteBreadcrumbStructuredData::trail($pages[0], $pages));

        $jsonLd = (new WebsiteBreadcrumbStructuredData())->build($area);
        $this->assertSame('Austin', $jsonLd['itemListElement'][1]['name']);
        $this->assertSame('https://x.test/serving-austin', $jsonLd['itemListElement'][1]['item']);
    }

    public function test_a_sub_page_renders_a_visible_breadcrumb_and_home_does_not(): void
    {
        $this->liveSite('crumbs.test');

        $about = $this->get('http://crumbs.test/about')->assertOk()->getContent();
        $this->assertStringContainsString('<nav class="website-breadcrumbs" aria-label="Breadcrumb">', $about);
        $this->assertStringContainsString('<li><a href="https://crumbs.test/">Home</a></li>', $about);

        $home = $this->get('http://crumbs.test/')->assertOk()->getContent();
        $this->assertStringNotContainsString('website-breadcrumbs', $home);
    }

    public function test_local_business_json_ld_is_one_site_entity_on_every_page(): void
    {
        $this->liveSite('entity.test');

        $entities = [];
        foreach (['/', '/about'] as $path) {
            $html = $this->get('http://entity.test'.$path)->assertOk()->getContent();
            preg_match_all('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches);

            foreach ($matches[1] as $json) {
                $data = json_decode($json, true);
                $this->assertIsArray($data, 'JSON-LD must parse');

                if (($data['@type'] ?? null) === 'LocalBusiness') {
                    $entities[] = $data;
                }
            }
        }

        $this->assertCount(2, $entities);
        $this->assertSame($entities[0]['@id'], $entities[1]['@id']);
        $this->assertSame('https://entity.test/', $entities[0]['url']);
        $this->assertSame('https://entity.test/', $entities[1]['url']);
        $this->assertArrayNotHasKey('aggregateRating', $entities[0]);
        $this->assertArrayNotHasKey('review', $entities[0]);
    }

    // ------------------------------------------------------- indexing intent

    public function test_letting_search_engines_in_never_releases_a_page_the_owner_hid_on_purpose(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['noindex' => true]);
        $generated = $this->subPage($website, 'about', ['noindex' => true]);
        $hidden = $this->subPage($website, 'private', ['noindex' => true, 'noindex_by_owner' => true]);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspace->uid, $business->uid]))
            ->assertSessionHas('message', '2 pages can now be found in search. Publish to update your live website.');

        $this->assertFalse($generated->fresh()->noindex);
        $this->assertTrue($hidden->fresh()->noindex, 'An owner-hidden page stays hidden.');
        $this->assertNotNull($website->fresh()->indexing_released_at);
    }

    public function test_ticking_hide_on_the_page_form_marks_the_page_as_the_owners_choice(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $page = $this->subPage($website, 'about');

        app(WebsiteDraftPageService::class)->updatePage($website, $page, ['title' => 'About', 'slug' => 'about', 'is_home' => false, 'noindex' => true, 'noindex_by_owner' => true]);
        $this->assertTrue($page->fresh()->noindex_by_owner);

        // A generator-style update (no owner flag) must not change or invent the owner's choice.
        app(WebsiteDraftPageService::class)->updatePage($website, $page->fresh(), ['title' => 'About', 'slug' => 'about', 'is_home' => false, 'noindex' => true]);
        $this->assertTrue($page->fresh()->noindex_by_owner);

        app(WebsiteDraftPageService::class)->updatePage($website, $page->fresh(), ['title' => 'About', 'slug' => 'about', 'is_home' => false, 'noindex' => false, 'noindex_by_owner' => false]);
        $this->assertFalse($page->fresh()->noindex_by_owner);
        $this->assertFalse($page->fresh()->noindex);
    }
    // ------------------------------------------------------------------ FAQ schema

    public function test_faq_schema_lists_exactly_the_questions_a_page_renders_and_only_on_that_page(): void
    {
        $faq = ['type' => 'faq', 'data' => ['heading' => 'Questions', 'items' => [
            ['question' => 'How long is a rental?', 'answer' => 'Most rentals run  three hours.'],
            ['question' => 'How long is a rental?', 'answer' => 'A duplicate is listed once.'],
            ['question' => 'Blank answer', 'answer' => '   '],
            ['question' => 'Do you travel?', 'answer' => 'Yes, across Chicago <and> beyond.'],
        ]]];

        $schema = \App\Library\Website\Seo\WebsiteFaqStructuredData::build([$faq]);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertSame(['How long is a rental?', 'Do you travel?'], array_column($schema['mainEntity'], 'name'));
        $this->assertSame('Most rentals run three hours.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertNull(\App\Library\Website\Seo\WebsiteFaqStructuredData::build([['type' => 'hero', 'data' => []]]));
        $this->assertNull(\App\Library\Website\Seo\WebsiteFaqStructuredData::build([['type' => 'faq', 'data' => ['items' => [['question' => 'Q', 'answer' => '']]]]]));

        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business, ['name' => 'Luma Booth Co']);
        $this->homePage($website, ['seo_title' => 'Luma Booth Co', 'meta_description' => 'Photo booth rentals in Chicago.']);
        $this->subPage($website, 'photo-booth-faq', ['title' => 'FAQ', 'seo_title' => 'FAQ | Luma Booth Co', 'meta_description' => 'Answers about booking.', 'sections' => [$this->section('hero'), ['type' => 'faq', 'data' => ['heading' => 'Questions', 'items' => [['question' => 'How long is a rental?', 'answer' => 'Most rentals run three hours.'], ['question' => 'Do you travel?', 'answer' => 'Yes, across Chicago and beyond.']]]]]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $this->activeDomain($website->fresh(), 'faq-schema.test');

        $withFaq = $this->get('http://faq-schema.test/photo-booth-faq')->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.+?)</script>#s', $withFaq, $blocks);
        $types = array_map(fn ($json) => json_decode($json, true)['@type'] ?? null, $blocks[1]);
        $this->assertContains('FAQPage', $types);
        $this->assertStringContainsString('How long is a rental?', $withFaq, 'The schema question is visible on the page.');

        $home = $this->get('http://faq-schema.test/')->assertOk()->getContent();
        $this->assertStringNotContainsString('FAQPage', $home, 'FAQPage is never emitted site-wide.');
    }
}