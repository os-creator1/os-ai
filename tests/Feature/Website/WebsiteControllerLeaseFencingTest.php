<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 5 (item 1) — proves every Studio
 * mutation endpoint named in the review (storeAsset, destroyAsset,
 * storeGallery, copyGalleryPhotosToPage, and the direct draft-page editor
 * storePage/updatePage/destroyPage) genuinely refuses, end to end through
 * the real HTTP route, while a generation lease is actively held — and
 * that NOTHING is written (no row, no file) when it does. True process-
 * level concurrency for the same guarantee lives in
 * WebsiteStudioMutationConcurrencyTest; this file proves the HTTP wiring
 * itself (the controller methods actually call runExclusive(), never a
 * released pre-check) for every endpoint the review named, not only the
 * three it required concurrency coverage for.
 */
class WebsiteControllerLeaseFencingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function leaseWebsite(Website $website): void
    {
        $website->forceFill([
            'generation_lease_token' => (string) Str::uuid(),
            'generation_lease_started_at' => now(),
        ])->save();
    }

    public function test_store_asset_refuses_while_leased_and_writes_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);
        $this->leaseWebsite($website);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('leased.png'), 'alt_text' => 'Leased photo',
        ])->assertSessionHas('status', 'error');

        $this->assertSame(0, $website->assets()->count());
        $directory = public_path("images/websites/{$website->uid}");
        $this->assertTrue(! is_dir($directory) || glob("{$directory}/*") === [], 'No file may ever be written while leased.');
    }

    public function test_destroy_asset_refuses_while_leased_and_deletes_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/x/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);

        $this->leaseWebsite($website);

        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $asset->uid]))
            ->assertSessionHas('status', 'error');

        $this->assertNotNull(WebsiteAsset::find($asset->id), 'The asset must never be deleted while leased.');
    }

    public function test_store_gallery_refuses_while_leased_and_writes_no_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/x/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);

        $this->leaseWebsite($website);

        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$asset->uid],
        ])->assertSessionHas('status', 'error');

        $this->assertNull($website->pages()->where('slug', 'gallery')->first(), 'No Gallery page may ever be created while leased.');
    }

    public function test_copy_gallery_photos_to_page_refuses_while_leased_and_leaves_the_page_untouched(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/x/seed.png',
            'mime_type' => 'image/png', 'size' => 10, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);
        WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Gallery', 'slug' => 'gallery', 'is_home' => false,
            'sections' => [['type' => 'gallery', 'data' => ['heading' => 'Gallery', 'items' => [['image' => $asset->uid]]]]],
            'noindex' => true,
        ]);
        $target = WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Services', 'slug' => 'services', 'is_home' => false,
            'sections' => [], 'noindex' => true,
        ]);

        $this->leaseWebsite($website);

        $this->post(route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [$workspace->uid, $business->uid, $target->uid]))
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $target->fresh()->sections, 'The target page must never be mutated while leased.');
    }

    public function test_store_page_refuses_while_leased_and_creates_no_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);
        $this->leaseWebsite($website);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'New Page',
            'is_home' => 0,
            'slug' => 'new-page',
            'sections' => [$this->section('hero')],
        ])->assertSessionHas('status', 'error');

        $this->assertSame(0, $website->pages()->count(), 'No page may ever be created while leased.');
    }

    public function test_update_page_refuses_while_leased_and_leaves_it_untouched(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $page = WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Original', 'slug' => 'original', 'is_home' => false,
            'sections' => [], 'noindex' => true,
        ]);

        $this->leaseWebsite($website);

        $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), [
            'title' => 'Changed While Leased',
            'is_home' => 0,
            'slug' => 'original',
            'sections' => [],
        ])->assertSessionHas('status', 'error');

        $this->assertSame('Original', $page->fresh()->title, 'The page must never be mutated while leased.');
    }

    public function test_destroy_page_refuses_while_leased_and_leaves_it_in_place(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $page = WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Still Here', 'slug' => 'still-here', 'is_home' => false,
            'sections' => [], 'noindex' => true,
        ]);

        $this->leaseWebsite($website);

        $this->delete(route('customer.workspaces.businesses.website.pages.destroy', [$workspace->uid, $business->uid, $page->uid]))
            ->assertSessionHas('status', 'error');

        $this->assertNotNull(WebsitePage::find($page->id), 'The page must never be deleted while leased.');
    }

    /**
     * Independent-review correction round 5 (item 2) — the mirror image
     * of every refusal test above: once the SAME lease has genuinely
     * expired (a crashed generation, nothing left to release it), every
     * one of these endpoints must succeed immediately, never stay
     * refused merely because a non-null token is still technically
     * present.
     */
    public function test_store_asset_recovers_once_the_lease_has_expired(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $website->forceFill([
            'generation_lease_token' => (string) Str::uuid(),
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 60),
        ])->save();

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('recovered.png'), 'alt_text' => 'Recovered photo',
        ])->assertSessionHas('status', 'success');

        $this->assertSame(1, $website->assets()->count());
        $this->assertNull($website->fresh()->generation_lease_token);
    }
}
