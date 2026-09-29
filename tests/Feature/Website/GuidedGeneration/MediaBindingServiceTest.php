<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\MediaBindingService;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Acceptance-correction Blocker 9. MediaBindingService is the ONLY
 * place a generated page ever receives an image reference — every test
 * here proves it only ever chooses among real, already-uploaded
 * WebsiteAsset rows belonging to the exact Website being bound, never
 * invents a purpose from an unlabeled photo, and always records a real
 * missing-media warning rather than silently leaving a broken slot.
 */
class MediaBindingServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function createAsset($website, ?string $altText = null): WebsiteAsset
    {
        return WebsiteAsset::create([
            'website_id' => $website->id,
            'disk' => 'public',
            'path' => 'images/websites/' . $website->uid . '/' . uniqid('', true) . '.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'alt_text' => $altText,
        ]);
    }

    private function homePageArray(): array
    {
        return [
            'page_key' => 'home',
            'page_type' => 'home',
            'is_home' => true,
            'slug' => null,
            'title' => 'Home',
            'seo_title' => null,
            'meta_description' => null,
            'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]],
        ];
    }

    public function test_no_assets_leaves_pages_unchanged_and_warns(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $result = app(MediaBindingService::class)->bind($website, [$this->homePageArray()]);

        $this->assertNull($result['pages'][0]['sections'][0]['data']['background_image'] ?? null);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('No uploaded photos', $result['warnings'][0]);
    }

    public function test_the_home_hero_is_bound_to_the_first_uploaded_asset(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $first = $this->createAsset($website, 'Booth setup at a wedding');
        $this->createAsset($website, 'Guests using the booth');

        $result = app(MediaBindingService::class)->bind($website, [$this->homePageArray()]);

        $this->assertSame($first->uid, $result['pages'][0]['sections'][0]['data']['background_image']);
    }

    public function test_distinct_assets_are_used_for_multiple_image_text_slots_never_repeating_the_hero(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $hero = $this->createAsset($website, 'Hero photo');
        $second = $this->createAsset($website, 'Second photo');
        $third = $this->createAsset($website, 'Third photo');

        $pages = [
            $this->homePageArray(),
            [
                'page_key' => 'service:a', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-a',
                'title' => 'Service A', 'seo_title' => null, 'meta_description' => null,
                'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => 'Service A']],
                    ['type' => 'image_text', 'data' => ['heading' => 'About', 'body' => 'x', 'image' => null, 'image_position' => 'left']],
                ],
            ],
            [
                'page_key' => 'service:b', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-b',
                'title' => 'Service B', 'seo_title' => null, 'meta_description' => null,
                'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => 'Service B']],
                    ['type' => 'image_text', 'data' => ['heading' => 'About', 'body' => 'x', 'image' => null, 'image_position' => 'left']],
                ],
            ],
        ];

        $result = app(MediaBindingService::class)->bind($website, $pages);

        $this->assertSame($hero->uid, $result['pages'][0]['sections'][0]['data']['background_image']);
        $serviceAImage = $result['pages'][1]['sections'][1]['data']['image'];
        $serviceBImage = $result['pages'][2]['sections'][1]['data']['image'];

        $this->assertContains($serviceAImage, [$second->uid, $third->uid]);
        $this->assertContains($serviceBImage, [$second->uid, $third->uid]);
        $this->assertNotSame($serviceAImage, $serviceBImage, 'Two distinct image_text slots must get two distinct photos when enough exist.');
        $this->assertNotSame($hero->uid, $serviceAImage);
        $this->assertNotSame($hero->uid, $serviceBImage);
    }

    public function test_binding_is_deterministic_across_repeated_calls(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->createAsset($website);
        $this->createAsset($website);

        $service = app(MediaBindingService::class);
        $first = $service->bind($website, [$this->homePageArray()]);
        $second = $service->bind($website->fresh(), [$this->homePageArray()]);

        $this->assertSame(
            $first['pages'][0]['sections'][0]['data']['background_image'],
            $second['pages'][0]['sections'][0]['data']['background_image']
        );
    }

    public function test_only_this_websites_own_assets_are_ever_chosen(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        // A Website has a unique business_id (one Website per Business),
        // so "a different website's assets" requires a wholly separate
        // tenant, never a second Website on the same Business.
        [, $otherBusiness] = $this->entitledTenant();
        $otherWebsite = $this->createWebsite($otherBusiness);
        $foreignAsset = $this->createAsset($otherWebsite, 'Belongs to a different website');

        $result = app(MediaBindingService::class)->bind($website, [$this->homePageArray()]);

        $this->assertNotSame($foreignAsset->uid, $result['pages'][0]['sections'][0]['data']['background_image'] ?? null);
        $this->assertNull($result['pages'][0]['sections'][0]['data']['background_image'] ?? null);
    }

    public function test_an_already_set_image_value_is_never_overwritten(): void
    {
        // Defense in depth: the output validator already refuses any
        // AI-authored image field before this service ever runs, so
        // this should never actually happen in production — but this
        // service must never silently replace a value that IS present
        // with one it "trusts" more, either.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->createAsset($website);

        $page = [
            'page_key' => 'service:a', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-a',
            'title' => 'Service A', 'seo_title' => null, 'meta_description' => null,
            'sections' => [
                ['type' => 'image_text', 'data' => ['heading' => 'About', 'body' => 'x', 'image' => 'already-set-value', 'image_position' => 'left']],
            ],
        ];

        $result = app(MediaBindingService::class)->bind($website, [$page]);

        $this->assertSame('already-set-value', $result['pages'][0]['sections'][0]['data']['image']);
    }

    public function test_a_single_asset_still_fills_every_slot_by_reuse_rather_than_leaving_one_broken(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->createAsset($website); // only the hero asset — no "remaining" pool

        $pages = [
            $this->homePageArray(),
            [
                'page_key' => 'service:a', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-a',
                'title' => 'Service A', 'seo_title' => null, 'meta_description' => null,
                'sections' => [['type' => 'image_text', 'data' => ['heading' => 'About', 'body' => 'x', 'image' => null, 'image_position' => 'left']]],
            ],
        ];

        $result = app(MediaBindingService::class)->bind($website, $pages);

        $this->assertNotEmpty($result['pages'][1]['sections'][0]['data']['image']);
        $this->assertNotEmpty($result['warnings'], 'A single asset genuinely repeating across the hero and an inline slot must still surface a warning.');
    }

    public function test_more_photo_slots_than_distinct_photos_produces_a_repeat_warning(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->createAsset($website); // hero
        $this->createAsset($website); // the only "remaining" pool asset

        $pages = [
            $this->homePageArray(),
            [
                'page_key' => 'service:a', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-a',
                'title' => 'Service A', 'seo_title' => null, 'meta_description' => null,
                'sections' => [['type' => 'image_text', 'data' => ['heading' => 'About', 'body' => 'x', 'image' => null, 'image_position' => 'left']]],
            ],
            [
                'page_key' => 'service:b', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-b',
                'title' => 'Service B', 'seo_title' => null, 'meta_description' => null,
                'sections' => [['type' => 'image_text', 'data' => ['heading' => 'About', 'body' => 'x', 'image' => null, 'image_position' => 'left']]],
            ],
        ];

        // Two image_text slots, only one non-hero asset available — the
        // second slot must repeat, and that repeat must be surfaced.
        $result = app(MediaBindingService::class)->bind($website, $pages);

        $this->assertNotEmpty($result['pages'][1]['sections'][0]['data']['image']);
        $this->assertNotEmpty($result['pages'][2]['sections'][0]['data']['image']);
        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'repeat')));
    }

    public function test_gallery_page_is_built_entirely_from_real_assets_never_from_ai_content(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $a = $this->createAsset($website);
        $b = $this->createAsset($website);

        $galleryPage = [
            'page_key' => 'gallery', 'page_type' => 'gallery', 'is_home' => false, 'slug' => 'gallery',
            'title' => 'Gallery', 'seo_title' => null, 'meta_description' => null,
            'sections' => [['type' => 'hero', 'data' => ['heading' => 'Gallery']]],
        ];

        $result = app(MediaBindingService::class)->bind($website, [$galleryPage]);

        $gallerySection = collect($result['pages'][0]['sections'])->firstWhere('type', 'gallery');
        $this->assertNotNull($gallerySection, 'MediaBindingService must append a real gallery section.');
        $imageUids = collect($gallerySection['data']['items'])->pluck('image')->all();
        $this->assertContains($a->uid, $imageUids);
        $this->assertContains($b->uid, $imageUids);
    }

    public function test_a_gallery_page_with_no_assets_gets_no_gallery_section_and_a_warning(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $galleryPage = [
            'page_key' => 'gallery', 'page_type' => 'gallery', 'is_home' => false, 'slug' => 'gallery',
            'title' => 'Gallery', 'seo_title' => null, 'meta_description' => null,
            'sections' => [['type' => 'hero', 'data' => ['heading' => 'Gallery']]],
        ];

        $result = app(MediaBindingService::class)->bind($website, [$galleryPage]);

        $gallerySection = collect($result['pages'][0]['sections'])->firstWhere('type', 'gallery');
        $this->assertNull($gallerySection);
        $this->assertNotEmpty($result['warnings']);
    }
}
