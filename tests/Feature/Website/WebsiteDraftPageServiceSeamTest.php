<?php

namespace Tests\Feature\Website;

use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Support\CanonicalJson;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.3 / §17.1 — the
 * "Draft page update service seam" regression.
 *
 * WebsiteDraftPageService is documented as the SOLE authorized path for
 * writing title/slug/seo_title/meta_description/noindex/sections on a
 * WebsitePage. This suite proves two things that must hold together for
 * that claim to mean anything:
 *
 *   (a) mechanically — WebsiteController itself never writes to
 *       website_pages directly (no WebsitePage::create, no
 *       $page->update(), no ->pages()->create() outside the service),
 *       and every mutation actually routes through $this->draftPages;
 *
 *   (b) behaviourally — every one of the six draft fields, exercised
 *       through the real HTTP pages.store / pages.update routes, is
 *       validated and persisted uniformly. In particular every
 *       length-limited field (title/slug/seo_title/meta_description)
 *       rejects an overlong value with a session error on that exact
 *       field — proving none of them silently skips validation by
 *       taking some other, unguarded path — and noindex is coerced to a
 *       real boolean rather than accepting an arbitrary string.
 *
 * (c) The homepage invariant (clearExistingHomepage) is proven to apply
 * even when is_home is not the only field changing in the request,
 * exercised purely through the controller/route — never by calling the
 * service directly.
 */
class WebsiteDraftPageServiceSeamTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    // ---------------------------------------------------------------
    // (a) Mechanical seam assertion
    // ---------------------------------------------------------------

    public function test_controller_never_writes_website_pages_directly_and_always_calls_the_seam(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Customer/Business/WebsiteController.php'));

        // True negatives, confirmed absent from the controller source
        // (WebsitePage::create and ->pages()->create only exist inside
        // WebsiteDraftPageService::createPage; $page->update( only
        // exists inside WebsiteDraftPageService::updatePage).
        $this->assertStringNotContainsString('WebsitePage::create', $source);
        $this->assertStringNotContainsString('$page->update(', $source);
        $this->assertStringNotContainsString('->pages()->create(', $source);

        // Positive proof every mutating action actually routes through
        // the injected seam rather than merely lacking a direct write.
        $this->assertStringContainsString('$this->draftPages->createPage(', $source);
        $this->assertStringContainsString('$this->draftPages->updatePage(', $source);
        $this->assertStringContainsString('$this->draftPages->deletePage(', $source);
    }

    // ---------------------------------------------------------------
    // (b) Integration proof — every field, both routes
    // ---------------------------------------------------------------

    public function test_store_page_persists_every_draft_field_through_the_seam(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $sections = [$this->section('text')];

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'About Us',
            'slug' => 'about-us',
            'is_home' => false,
            'seo_title' => 'About Us | Acme',
            'meta_description' => 'Learn more about our company.',
            'noindex' => '1',
            'sections' => json_encode($sections),
        ]);

        $response->assertRedirect();

        $page = WebsitePage::where('website_id', $website->id)->where('slug', 'about-us')->firstOrFail();

        $this->assertSame('About Us', $page->title);
        $this->assertSame('about-us', $page->slug);
        $this->assertFalse($page->is_home);
        $this->assertSame('About Us | Acme', $page->seo_title);
        $this->assertSame('Learn more about our company.', $page->meta_description);
        $this->assertTrue($page->noindex);
        // `sections` is a MySQL `json` column and MySQL normalises the
        // key order of every object it stores, so the read-back never
        // matches the in-memory fixture by raw array identity. Compared
        // canonically instead: every key, every value and every list
        // ORDER is still asserted exactly — only object key order, which
        // JSON does not define, is normalised on both sides.
        $this->assertSame(CanonicalJson::canonicalize($sections), CanonicalJson::canonicalize($page->sections));
    }

    public function test_update_page_persists_every_draft_field_through_the_seam(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $page = $this->subPage($website, 'services');
        $this->authenticateAsCustomer($customer);

        $sections = [$this->section('faq')];

        $response = $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), [
            'title' => 'Our Services',
            'slug' => 'our-services',
            'is_home' => false,
            'seo_title' => 'Our Services | Acme',
            'meta_description' => 'What we offer.',
            'noindex' => '0',
            'sections' => json_encode($sections),
        ]);

        $response->assertRedirect();

        $page->refresh();

        $this->assertSame('Our Services', $page->title);
        $this->assertSame('our-services', $page->slug);
        $this->assertFalse($page->is_home);
        $this->assertSame('Our Services | Acme', $page->seo_title);
        $this->assertSame('What we offer.', $page->meta_description);
        $this->assertFalse($page->noindex);
        // `sections` is a MySQL `json` column and MySQL normalises the
        // key order of every object it stores, so the read-back never
        // matches the in-memory fixture by raw array identity. Compared
        // canonically instead: every key, every value and every list
        // ORDER is still asserted exactly — only object key order, which
        // JSON does not define, is normalised on both sides.
        $this->assertSame(CanonicalJson::canonicalize($sections), CanonicalJson::canonicalize($page->sections));
    }

    public function test_noindex_is_coerced_to_a_real_boolean_rather_than_an_arbitrary_string(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'No Index Page',
            'slug' => 'no-index-page',
            'is_home' => false,
            'noindex' => 'true',
            'sections' => json_encode([]),
        ])->assertRedirect();

        $noindexed = WebsitePage::where('website_id', $website->id)->where('slug', 'no-index-page')->firstOrFail();
        $this->assertTrue($noindexed->noindex);
        $this->assertIsBool($noindexed->noindex);

        // Omitted entirely -> coerced to false, never left null or a
        // stray truthy/falsy string.
        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Indexed Page',
            'slug' => 'indexed-page',
            'is_home' => false,
            'sections' => json_encode([]),
        ])->assertRedirect();

        $indexed = WebsitePage::where('website_id', $website->id)->where('slug', 'indexed-page')->firstOrFail();
        $this->assertFalse($indexed->noindex);
        $this->assertIsBool($indexed->noindex);
    }

    public function test_store_page_rejects_title_over_max_length(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => str_repeat('a', 151),
            'slug' => 'valid-slug-title',
            'is_home' => false,
            'sections' => json_encode([]),
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id, 'slug' => 'valid-slug-title']);
    }

    public function test_store_page_rejects_slug_over_max_length(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Valid Title',
            'slug' => str_repeat('a', 81),
            'is_home' => false,
            'sections' => json_encode([]),
        ]);

        $response->assertSessionHasErrors('slug');
        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_store_page_rejects_seo_title_over_max_length(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Valid Title',
            'slug' => 'valid-slug-seo-title',
            'is_home' => false,
            'seo_title' => str_repeat('a', 71),
            'sections' => json_encode([]),
        ]);

        $response->assertSessionHasErrors('seo_title');
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id, 'slug' => 'valid-slug-seo-title']);
    }

    public function test_store_page_rejects_meta_description_over_max_length(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Valid Title',
            'slug' => 'valid-slug-meta-desc',
            'is_home' => false,
            'meta_description' => str_repeat('a', 161),
            'sections' => json_encode([]),
        ]);

        $response->assertSessionHasErrors('meta_description');
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id, 'slug' => 'valid-slug-meta-desc']);
    }

    public function test_update_page_rejects_the_same_four_length_limited_fields_identically(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $page = $this->subPage($website, 'contact');
        $this->authenticateAsCustomer($customer);

        $updateRoute = fn () => route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]);

        $this->put($updateRoute(), [
            'title' => str_repeat('a', 151),
            'slug' => 'contact',
            'is_home' => false,
            'sections' => json_encode([]),
        ])->assertSessionHasErrors('title');

        $this->put($updateRoute(), [
            'title' => 'Contact',
            'slug' => str_repeat('a', 81),
            'is_home' => false,
            'sections' => json_encode([]),
        ])->assertSessionHasErrors('slug');

        $this->put($updateRoute(), [
            'title' => 'Contact',
            'slug' => 'contact',
            'is_home' => false,
            'seo_title' => str_repeat('a', 71),
            'sections' => json_encode([]),
        ])->assertSessionHasErrors('seo_title');

        $this->put($updateRoute(), [
            'title' => 'Contact',
            'slug' => 'contact',
            'is_home' => false,
            'meta_description' => str_repeat('a', 161),
            'sections' => json_encode([]),
        ])->assertSessionHasErrors('meta_description');

        // None of the four rejected attempts touched the persisted row.
        $page->refresh();
        $this->assertSame('Contact', $page->title);
        $this->assertSame('contact', $page->slug);
    }

    // ---------------------------------------------------------------
    // (c) Homepage invariant holds identically when other fields change
    // ---------------------------------------------------------------

    public function test_promoting_a_page_to_home_while_also_changing_its_title_clears_the_previous_homepage(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $home = $this->homePage($website);
        $sub = $this->subPage($website, 'about');
        $this->authenticateAsCustomer($customer);

        $response = $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $sub->uid]), [
            'title' => 'New Homepage Title',
            'is_home' => true,
            'sections' => json_encode([$this->section('hero')]),
        ]);

        $response->assertRedirect();

        $home->refresh();
        $sub->refresh();

        $this->assertFalse($home->is_home);
        $this->assertTrue($sub->is_home);
        $this->assertSame('New Homepage Title', $sub->title);
        $this->assertNull($sub->slug);

        // Exactly one homepage survives the combined field change.
        $this->assertSame(1, $website->pages()->where('is_home', true)->count());
    }
}
