<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.9 (SEO).
 *
 * Scope: page-level SEO field escaping in the public HTML head, canonical
 * URL correctness for the home page and a slugged sub-page, the
 * per-page noindex field surviving a full publish/snapshot round trip,
 * the sitemap reflecting only the currently-published snapshot (never
 * unpublished draft changes), the sitemap route resolving successfully
 * despite being extensionless, and the 'sitemap' reserved slug being
 * rejected at page-creation time.
 *
 * Deliberately EXCLUDED here (covered by a separate Indexing test file):
 * the X-Robots-Tag response header and any robots.txt behavior.
 */
class WebsiteSeoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_seo_title_and_meta_description_render_escaped_in_public_html_head(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $this->homePage($website, [
            'seo_title' => 'Title <b>bold</b>',
            'meta_description' => 'Desc <i>italic</i> & more',
        ]);

        app(WebsitePublisher::class)->publish($website, $business->customer_id);

        $response = $this->get(route('public.website.home', $website->public_id));

        $response->assertOk();
        $response->assertDontSee('<b>bold</b>', false);
        $response->assertDontSee('<i>italic</i>', false);
        $response->assertSee(e('Title <b>bold</b>'), false);
        $response->assertSee(e('Desc <i>italic</i> & more'), false);
    }

    public function test_canonical_url_is_correct_for_home_page(): void
    {
        // The platform path is never indexable, and with no active
        // custom domain there is no address more canonical than this
        // one — resources/views/public/website/page.blade.php therefore
        // omits the tag entirely here (see
        // Public\WebsiteController::renderPage()) rather than
        // self-referencing a URL that is never meant to be indexed.
        // What this proves instead: route() itself generates the exact
        // literal public URL for the home page, and that URL resolves.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        app(WebsitePublisher::class)->publish($website, $business->customer_id);

        $canonicalUrl = route('public.website.home', $website->public_id);

        $this->assertSame(url('/sites/' . $website->public_id), $canonicalUrl);
        $this->get($canonicalUrl)->assertOk()->assertDontSee('rel="canonical"', false);
    }

    /**
     * Contract §7.5 — the platform-path renderer shares the exact same
     * `contact_details` component as a custom domain, so revoking
     * address privacy without a republish must be withheld here too,
     * on the very next request.
     */
    public function test_platform_path_contact_details_withholds_a_revoked_address_without_republishing(): void
    {
        [, $business] = $this->entitledTenant();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $business->customer_id);

        $this->get(route('public.website.home', $website->public_id))->assertOk()->assertSee('123 Main St');

        $location->update(['public_address' => false]);

        $this->get(route('public.website.home', $website->public_id))->assertOk()->assertDontSee('123 Main St');
    }

    public function test_canonical_url_is_correct_for_a_slugged_sub_page(): void
    {
        // Same caveat as the home-page test above: no active domain, so
        // the tag is omitted — this proves route-generation correctness
        // for the sub-page URL directly.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');

        app(WebsitePublisher::class)->publish($website, $business->customer_id);

        $canonicalUrl = route('public.website.page', [$website->public_id, 'about']);

        $this->assertSame(url('/sites/' . $website->public_id . '/about'), $canonicalUrl);
        $this->get($canonicalUrl)->assertOk()->assertDontSee('rel="canonical"', false);
    }

    public function test_platform_path_moves_permanently_to_the_active_custom_domain_when_one_exists(): void
    {
        // Both hosts used to serve identical content, the platform path with
        // noindex AND a canonical to the custom domain (a mixed signal). It
        // now answers a 301 to the one real, indexable address.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $business->customer_id);
        $website->domains()->create([
            'domain' => 'canonical-target.test',
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->get(route('public.website.home', $website->public_id))
            ->assertStatus(301)
            ->assertRedirect('https://canonical-target.test/');

        $this->get(route('public.website.page', [$website->public_id, 'about']))
            ->assertStatus(301)
            ->assertRedirect('https://canonical-target.test/about');
    }

    public function test_per_page_noindex_value_survives_publish_snapshot_round_trip(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $home = $this->homePage($website, ['noindex' => true]);
        $about = $this->subPage($website, 'about', ['noindex' => false]);

        $revision = app(WebsitePublisher::class)->publish($website, $business->customer_id);

        $snapshot = $revision->fresh()->snapshot;
        $pagesByUid = collect($snapshot['pages'])->keyBy('uid');

        $this->assertTrue($pagesByUid[$home->uid]['seo']['noindex']);
        $this->assertFalse($pagesByUid[$about->uid]['seo']['noindex']);
    }

    public function test_custom_domain_sitemap_includes_only_pages_from_the_current_published_snapshot(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');

        app(WebsitePublisher::class)->publish($website, $business->customer_id);
        $website->domains()->create([
            'domain' => 'snapshot-sitemap.test',
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        // Added to the draft AFTER the only publish so far — must never
        // appear in the sitemap, which reflects only the published
        // snapshot.
        $this->subPage($website, 'contact');

        $response = $this->get('http://snapshot-sitemap.test/sitemap.xml');

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('<loc>https://snapshot-sitemap.test/</loc>', $body);
        $this->assertStringContainsString('<loc>https://snapshot-sitemap.test/about</loc>', $body);
        $this->assertStringNotContainsString('contact', $body);
    }

    public function test_custom_domain_sitemap_is_valid_xml_with_the_xml_content_type(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        app(WebsitePublisher::class)->publish($website, $business->customer_id);
        $website->domains()->create([
            'domain' => 'xml-sitemap.test',
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        $response = $this->get('http://xml-sitemap.test/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('<?xml', $response->getContent());
        $this->assertNotFalse(simplexml_load_string($response->getContent()));
    }

    public function test_platform_path_has_no_sitemap_of_its_own(): void
    {
        // The platform path is never indexable, so a sitemap listing its URLs
        // would list only noindex pages. With no custom domain it is a 404.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        app(WebsitePublisher::class)->publish($website, $business->customer_id);

        $this->get(route('public.website.sitemap', $website->public_id))->assertNotFound();

        $website->domains()->create([
            'domain' => 'platform-sitemap-redirect.test',
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->get(route('public.website.sitemap', $website->public_id))
            ->assertStatus(301)
            ->assertRedirect('https://platform-sitemap-redirect.test/sitemap.xml');
    }

    public function test_creating_page_with_reserved_sitemap_slug_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Site Map',
            'slug' => 'sitemap',
            'is_home' => false,
            'sections' => [$this->section('text')],
        ]);

        $response->assertSessionHasErrors('slug');
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id, 'slug' => 'sitemap']);
    }
}
