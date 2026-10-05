<?php

namespace Tests\Feature\Website\Public;

use App\Library\Website\WebsitePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.10 (Indexing).
 *
 * Scope: the X-Robots-Tag: noindex, follow response header on both the
 * public home route and a slugged sub-page route, public/robots.txt
 * being untouched by this feature branch, the sitemap continuing to
 * list every page from the published snapshot regardless of each
 * page's own noindex value (noindex only ever suppresses a search
 * engine visiting/indexing that page, never its sitemap presence), and
 * the noindex field's exact boolean value surviving a publish/snapshot
 * round trip (a short, indexing-focused restatement of the fuller proof
 * in WebsiteSeoTest — see that file's
 * test_per_page_noindex_value_survives_publish_snapshot_round_trip for
 * the canonical version of this scenario).
 */
class WebsiteIndexingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_public_home_route_carries_the_noindex_follow_robots_tag_header(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
    }

    public function test_public_slugged_page_route_carries_the_same_robots_tag_header(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.page', [$website->public_id, 'about']))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
    }

    public function test_platform_robots_txt_is_generated_with_unix_line_endings_and_blocks_nothing(): void
    {
        // The static public/robots.txt was removed: a checked-in file picked up CRLF
        // endings on a Windows checkout, and a static file could never carry a
        // per-domain Sitemap line. One generator now serves every host.
        $this->assertFileDoesNotExist(base_path('public/robots.txt'));

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame("User-agent: *\nDisallow:\n", $response->getContent());
    }

    public function test_custom_domain_robots_txt_announces_the_sitemap_only_when_something_is_indexable(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about', ['noindex' => true]);

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->domains()->create([
            'domain' => 'robots-domain.test',
            'is_primary' => true,
            'status' => \App\Enums\Website\WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        $response = $this->get('http://robots-domain.test/robots.txt');

        $response->assertOk();
        $this->assertSame("User-agent: *\nDisallow:\n\nSitemap: https://robots-domain.test/sitemap.xml\n", $response->getContent());

        // Nothing indexable at all: no Sitemap line, and the sitemap is a 404 (never an empty urlset).
        $website->pages()->update(['noindex' => true]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame("User-agent: *\nDisallow:\n", $this->get('http://robots-domain.test/robots.txt')->getContent());
        $this->get('http://robots-domain.test/sitemap.xml')->assertNotFound();
    }

    public function test_custom_domain_sitemap_lists_only_indexable_pages(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $this->homePage($website, ['noindex' => true]);
        $this->subPage($website, 'about', ['noindex' => false]);

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->domains()->create([
            'domain' => 'indexable-only.test',
            'is_primary' => true,
            'status' => \App\Enums\Website\WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        $body = $this->get('http://indexable-only.test/sitemap.xml')->assertOk()->getContent();

        // A noindex page is never a URL a sitemap asks a crawler to discover.
        $this->assertStringNotContainsString('<loc>https://indexable-only.test/</loc>', $body);
        $this->assertStringContainsString('<loc>https://indexable-only.test/about</loc>', $body);
    }

    public function test_noindex_field_is_present_with_its_exact_boolean_value_in_the_published_snapshot(): void
    {
        // Short, indexing-focused restatement of WebsiteSeoTest's fuller
        // round-trip proof: confirms the field exists in the snapshot's
        // seo block with the exact boolean it was set to, ready for
        // Slice B to key off of.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $home = $this->homePage($website, ['noindex' => true]);
        $about = $this->subPage($website, 'about', ['noindex' => false]);

        $revision = app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $pagesByUid = collect($revision->fresh()->snapshot['pages'])->keyBy('uid');

        $this->assertArrayHasKey('noindex', $pagesByUid[$home->uid]['seo']);
        $this->assertSame(true, $pagesByUid[$home->uid]['seo']['noindex']);

        $this->assertArrayHasKey('noindex', $pagesByUid[$about->uid]['seo']);
        $this->assertSame(false, $pagesByUid[$about->uid]['seo']['noindex']);
    }
}
