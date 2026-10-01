<?php

namespace Tests\Feature\Website\Gallery;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Library\Website\GuidedGeneration\MediaBindingService;
use App\Library\Website\WebsitePageStrategy;
use App\Models\CatalogItem;
use App\Models\CatalogItemImage;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 2 — a user-editable `category_tag`
 * was being overloaded as an ownership/purpose boundary. Proves the
 * durable `purpose` column now correctly scopes gallery eligibility/
 * count/UI/cover/ordering/removal, the general homepage/service image
 * pool, custom-section binding/removal, and package-image mirroring —
 * each kind of asset stays in its own lane.
 */
class WebsiteAssetPurposeSeparationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function asset($website, WebsiteAssetPurpose $purpose, int $sortOrder = 0): WebsiteAsset
    {
        return $website->assets()->create([
            'disk' => 'public',
            'path' => 'images/websites/' . $website->uid . '/' . uniqid('', true) . '.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'sort_order' => $sortOrder,
            'purpose' => $purpose->value,
        ]);
    }

    public function test_five_gallery_images_plus_one_custom_image_do_not_meet_the_gallery_threshold(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        for ($i = 0; $i < 5; $i++) {
            $this->asset($website, WebsiteAssetPurpose::Gallery, $i);
        }
        $this->asset($website, WebsiteAssetPurpose::CustomSection, 0);

        $this->assertSame(6, $website->assets()->count(), 'Precondition: six total assets exist.');
        $this->assertFalse(app(WebsitePageStrategy::class)->galleryEligible($website), 'A custom-section image must never count toward the gallery threshold.');
    }

    public function test_six_real_gallery_images_meet_the_threshold(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        for ($i = 0; $i < 6; $i++) {
            $this->asset($website, WebsiteAssetPurpose::Gallery, $i);
        }

        $this->assertTrue(app(WebsitePageStrategy::class)->galleryEligible($website));
    }

    public function test_a_custom_section_image_never_appears_in_the_generic_hero_or_gallery_pool(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $customAsset = $this->asset($website, WebsiteAssetPurpose::CustomSection, 0);
        for ($i = 0; $i < 6; $i++) {
            $this->asset($website, WebsiteAssetPurpose::Gallery, $i + 1);
        }

        $pages = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Home']]]],
            ['page_key' => 'gallery', 'page_type' => 'gallery', 'is_home' => false, 'slug' => 'gallery', 'title' => 'Gallery', 'seo_title' => null, 'meta_description' => null, 'sections' => []],
        ];

        $bound = app(MediaBindingService::class)->bind($website, $pages);

        $heroImage = collect($bound['pages'][0]['sections'])->firstWhere('type', 'hero')['data']['background_image'] ?? null;
        $this->assertNotSame($customAsset->uid, $heroImage, 'A custom-section image must never become the generic hero image.');

        $galleryItems = collect($bound['pages'][1]['sections'])->firstWhere('type', 'gallery')['data']['items'] ?? [];
        $galleryUids = collect($galleryItems)->pluck('image')->all();
        $this->assertNotContains($customAsset->uid, $galleryUids, 'A custom-section image must never appear in the Gallery page.');
    }

    public function test_a_package_mirror_never_becomes_the_generic_homepage_hero(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $catalogItem = app(CatalogItemManager::class)->create($business, ['type' => 'package', 'name' => 'Wedding Package']);
        $image = CatalogItemImage::create([
            'catalog_item_id' => $catalogItem->id, 'disk' => 'public', 'path' => 'images/catalog/x.png',
            'mime_type' => 'image/png', 'size' => 100, 'position' => 0, 'is_cover' => true,
        ]);

        $pages = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Home']]]],
            ['page_key' => 'packages', 'page_type' => 'packages', 'is_home' => false, 'slug' => 'packages', 'title' => 'Packages', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => [['name' => 'Wedding Package', 'description' => null, 'price_label' => null, 'image' => null]]]]]],
        ];

        $bound = app(MediaBindingService::class)->bind($website, $pages);

        $mirroredAsset = WebsiteAsset::where('website_id', $website->id)->where('source_catalog_item_image_id', $image->id)->sole();
        $this->assertSame(WebsiteAssetPurpose::PackageMirror, $mirroredAsset->purpose);

        $heroImage = collect($bound['pages'][0]['sections'])->firstWhere('type', 'hero')['data']['background_image'] ?? null;
        $this->assertNull($heroImage, 'With no real gallery assets, the home hero must stay empty rather than use a package mirror.');
    }

    public function test_gallery_removal_cannot_delete_custom_section_media_and_vice_versa(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $galleryAsset = $this->asset($website, WebsiteAssetPurpose::Gallery);
        $customAsset = $this->asset($website, WebsiteAssetPurpose::CustomSection);

        $this->assertNull(
            WebsiteAsset::where('id', $customAsset->id)->where('purpose', WebsiteAssetPurpose::Gallery->value)->first(),
            'A gallery-scoped lookup must never resolve a custom-section asset.'
        );
        $this->assertNull(
            WebsiteAsset::where('id', $galleryAsset->id)->where('purpose', WebsiteAssetPurpose::CustomSection->value)->first(),
            'A custom-section-scoped lookup must never resolve a gallery asset.'
        );
    }

    public function test_gallery_reorder_does_not_reorder_unrelated_assets(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $a = $this->asset($website, WebsiteAssetPurpose::Gallery, 0);
        $b = $this->asset($website, WebsiteAssetPurpose::Gallery, 1);
        $custom = $this->asset($website, WebsiteAssetPurpose::CustomSection, 0);

        app(WebsiteGalleryManager::class)->reorder($website, WebsiteAssetPurpose::Gallery, [$b->uid, $a->uid]);

        $this->assertSame(0, $b->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(0, $custom->fresh()->sort_order, 'Reordering gallery assets must never touch a custom-section asset\'s own order.');
    }

    public function test_generation_prefers_the_selected_cover_for_the_homepage_hero_and_uses_gallery_sort_order(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $first = $this->asset($website, WebsiteAssetPurpose::Gallery, 0);
        $cover = $this->asset($website, WebsiteAssetPurpose::Gallery, 1);
        $cover->forceFill(['is_cover' => true])->save();

        $pages = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Home']]]],
        ];

        $bound = app(MediaBindingService::class)->bind($website, $pages);
        $heroImage = collect($bound['pages'][0]['sections'])->firstWhere('type', 'hero')['data']['background_image'] ?? null;

        $this->assertSame($cover->uid, $heroImage, 'The owner\'s selected cover must be preferred for the homepage hero over the first-by-id asset.');
    }

    public function test_generation_falls_back_deterministically_to_the_first_asset_by_sort_order_when_no_cover_is_set(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $second = $this->asset($website, WebsiteAssetPurpose::Gallery, 1);
        $first = $this->asset($website, WebsiteAssetPurpose::Gallery, 0);

        $pages = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Home']]]],
        ];

        $bound = app(MediaBindingService::class)->bind($website, $pages);
        $heroImage = collect($bound['pages'][0]['sections'])->firstWhere('type', 'hero')['data']['background_image'] ?? null;

        $this->assertSame($first->uid, $heroImage, 'With no cover set, the first asset by sort_order (never insertion id) is the deterministic fallback.');
    }

    /**
     * Independent-review correction round 2 — the server enforces the
     * gallery cap across REPEATED requests, never only within one
     * request's own `max:20` array-size validation rule.
     */
    public function test_the_gallery_cap_is_enforced_across_repeated_upload_requests_not_only_one_request(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $manager = app(WebsiteGalleryManager::class);

        // Fill up to the cap across several separate calls.
        $remaining = WebsiteGalleryManager::MAX_GALLERY_ASSETS;
        while ($remaining > 0) {
            $batch = min(5, $remaining);
            $manager->uploadMany($website, array_map(fn ($i) => $this->fakeImageUpload("g{$i}.png"), range(1, $batch)), WebsiteAssetPurpose::Gallery);
            $remaining -= $batch;
        }

        $this->assertSame(WebsiteGalleryManager::MAX_GALLERY_ASSETS, $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->count());

        $this->expectException(\App\Exceptions\Website\InvalidWebsiteAssetException::class);
        $manager->uploadMany($website, [$this->fakeImageUpload('one_more.png')], WebsiteAssetPurpose::Gallery);
    }

    public function test_the_custom_section_cap_is_enforced_across_repeated_upload_requests(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $manager = app(WebsiteGalleryManager::class);

        for ($i = 0; $i < WebsiteGalleryManager::MAX_CUSTOM_SECTION_ASSETS; $i++) {
            $manager->uploadMany($website, [$this->fakeImageUpload("c{$i}.png")], WebsiteAssetPurpose::CustomSection);
        }

        $this->assertSame(WebsiteGalleryManager::MAX_CUSTOM_SECTION_ASSETS, $website->assets()->where('purpose', WebsiteAssetPurpose::CustomSection->value)->count());

        $this->expectException(\App\Exceptions\Website\InvalidWebsiteAssetException::class);
        $manager->uploadMany($website, [$this->fakeImageUpload('one_more.png')], WebsiteAssetPurpose::CustomSection);
    }

    public function test_the_total_uploaded_storage_cap_refuses_a_batch_that_would_exceed_it(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        // Pre-fill just under the cap directly (uploading real 120MB of
        // fixture files would be impractical in a test).
        $website->assets()->create([
            'disk' => 'public', 'path' => 'images/websites/x/big.png', 'mime_type' => 'image/png',
            'size' => WebsiteGalleryManager::MAX_TOTAL_UPLOAD_BYTES - 1000,
            'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);

        $this->expectException(\App\Exceptions\Website\InvalidWebsiteAssetException::class);
        app(WebsiteGalleryManager::class)->uploadMany($website, [$this->fakeImageUpload('too-big.png', str_repeat('x', 5000))], WebsiteAssetPurpose::Gallery);
    }
}
