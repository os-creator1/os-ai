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

    public function test_robots_txt_is_untouched_by_this_feature_branch(): void
    {
        // The platform host's robots.txt still says exactly what the original static public/robots.txt said
        // ("everything allowed"). It is now a route rather than a file (a file would be served by the web
        // server ahead of a customer domain's own robots.txt, which carries that site's Sitemap line), so the
        // contract is asserted on what is SERVED, byte for byte — and that no static file shadows it.
        $this->assertFileDoesNotExist(base_path('public/robots.txt'));

        $response = $this->get('/robots.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame("User-agent: *\nDisallow:\n", $response->getContent());
    }

    public function test_sitemap_lists_every_published_page_regardless_of_its_own_noindex_value(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $this->homePage($website, ['noindex' => true]);
        $this->subPage($website, 'about', ['noindex' => false]);

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $response = $this->get(route('public.website.sitemap', $website->public_id));
        $response->assertOk();

        $body = $response->getContent();

        // Both pages must appear as <url><loc> entries — noindex=true on
        // the home page does not exclude it from the sitemap, only the
        // HTTP header (asserted above) tells a crawler not to index it.
        $this->assertStringContainsString(
            '<url><loc>' . e(route('public.website.home', $website->public_id)) . '</loc></url>',
            $body
        );
        $this->assertStringContainsString(
            '<url><loc>' . e(route('public.website.page', [$website->public_id, 'about'])) . '</loc></url>',
            $body
        );
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
