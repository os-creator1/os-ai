<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteSlugRules;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.2 — Domain model
 * invariants: one Website per Business, public_id uniqueness/identity,
 * exactly one homepage per Website, and slug uniqueness/format rules
 * (reserved words, path-traversal-shaped input, non-ASCII input).
 */
class WebsiteDomainModelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_a_business_may_have_at_most_one_website(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'name' => 'A second website attempt',
        ])->assertRedirect(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]));

        $this->assertSame(1, Website::where('business_id', $business->id)->count());
    }

    public function test_website_public_id_is_unique_and_never_equals_the_owning_business_uid(): void
    {
        [, $businessOne] = $this->entitledTenant();
        [, $businessTwo] = $this->entitledTenant();

        $websiteOne = $this->createWebsite($businessOne);
        $websiteTwo = $this->createWebsite($businessTwo);

        $this->assertNotSame($websiteOne->public_id, $websiteTwo->public_id);

        $this->assertNotSame($websiteOne->public_id, $businessOne->uid);
        $this->assertNotSame($websiteTwo->public_id, $businessTwo->uid);
        $this->assertNotSame($websiteOne->public_id, $businessTwo->uid);
        $this->assertNotSame($websiteTwo->public_id, $businessOne->uid);
    }

    public function test_exactly_one_homepage_is_enforced_across_sequential_page_store_requests(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.website.pages.store', $params), [
            'title' => 'Home A',
            'is_home' => true,
            'sections' => [$this->section('hero')],
        ])->assertSessionHasNoErrors();

        $this->post(route('customer.workspaces.businesses.website.pages.store', $params), [
            'title' => 'Home B',
            'is_home' => true,
            'sections' => [$this->section('text')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, WebsitePage::where('website_id', $website->id)->where('is_home', true)->count());

        $homepage = WebsitePage::where('website_id', $website->id)->where('is_home', true)->first();
        $this->assertSame('Home B', $homepage->title);

        $this->assertSame(2, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_slug_is_unique_within_a_website_but_not_across_websites(): void
    {
        [$customerOne, $businessOne, $workspaceOne] = $this->entitledTenant();
        $websiteOne = $this->createWebsite($businessOne);
        $this->authenticateAsCustomer($customerOne);

        $paramsOne = [$workspaceOne->uid, $businessOne->uid];

        $this->post(route('customer.workspaces.businesses.website.pages.store', $paramsOne), [
            'title' => 'About',
            'slug' => 'about',
            'is_home' => false,
            'sections' => [$this->section('text')],
        ])->assertSessionHasNoErrors();

        $this->post(route('customer.workspaces.businesses.website.pages.store', $paramsOne), [
            'title' => 'About Again',
            'slug' => 'about',
            'is_home' => false,
            'sections' => [$this->section('text')],
        ])->assertSessionHasErrors('slug');

        $this->assertSame(1, WebsitePage::where('website_id', $websiteOne->id)->where('slug', 'about')->count());

        // The same slug on a DIFFERENT Website is unaffected.
        [$customerTwo, $businessTwo, $workspaceTwo] = $this->entitledTenant();
        $websiteTwo = $this->createWebsite($businessTwo);
        $this->authenticateAsCustomer($customerTwo);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspaceTwo->uid, $businessTwo->uid]), [
            'title' => 'About',
            'slug' => 'about',
            'is_home' => false,
            'sections' => [$this->section('text')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, WebsitePage::where('website_id', $websiteTwo->id)->where('slug', 'about')->count());
    }

    public function test_every_reserved_slug_is_rejected_for_a_non_home_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid];

        foreach (WebsiteSlugRules::RESERVED as $reserved) {
            $this->post(route('customer.workspaces.businesses.website.pages.store', $params), [
                'title' => 'Reserved slug attempt',
                'slug' => $reserved,
                'is_home' => false,
                'sections' => [$this->section('text')],
            ])->assertSessionHasErrors('slug');

            $this->assertDatabaseMissing('website_pages', ['slug' => $reserved]);
        }
    }

    public function test_path_traversal_encoded_slash_and_uppercase_slugs_are_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid];

        $invalidSlugs = ['../etc', 'a/b', 'a%2Fb', 'a..b', 'UPPER-CASE'];

        foreach ($invalidSlugs as $slug) {
            $this->assertFalse(WebsiteSlugRules::isValid($slug), "Expected slug '{$slug}' to be invalid per WebsiteSlugRules.");

            $this->post(route('customer.workspaces.businesses.website.pages.store', $params), [
                'title' => 'Invalid slug attempt',
                'slug' => $slug,
                'is_home' => false,
                'sections' => [$this->section('text')],
            ])->assertSessionHasErrors('slug');

            $this->assertDatabaseMissing('website_pages', ['slug' => $slug]);
        }
    }

    public function test_non_ascii_slugs_are_rejected_outright_never_transliterated(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid];

        $nonAsciiSlugs = ['café', 'wörld', 'party🎉time'];

        foreach ($nonAsciiSlugs as $slug) {
            $this->assertFalse(WebsiteSlugRules::isValid($slug), "Expected non-ASCII slug '{$slug}' to be invalid.");

            $this->post(route('customer.workspaces.businesses.website.pages.store', $params), [
                'title' => 'Non-ASCII slug attempt',
                'slug' => $slug,
                'is_home' => false,
                'sections' => [$this->section('text')],
            ])->assertSessionHasErrors('slug');

            // Nothing is persisted at all — not the literal input, and
            // certainly no transliterated fallback (e.g. "cafe").
            $this->assertDatabaseMissing('website_pages', ['slug' => $slug]);
        }

        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }
}
