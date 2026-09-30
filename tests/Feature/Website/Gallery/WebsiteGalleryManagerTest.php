<?php

namespace Tests\Feature\Website\Gallery;

use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Builder redesign — proves the v1 gallery step's reorder/cover
 * mechanics (multi-upload itself just loops the already-tested
 * WebsiteAssetUploadService, so is not re-proven here).
 */
class WebsiteGalleryManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function asset($website, int $sortOrder = 0): WebsiteAsset
    {
        return WebsiteAsset::create([
            'website_id' => $website->id,
            'disk' => 'public',
            'path' => 'images/websites/' . $website->uid . '/' . uniqid('', true) . '.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'sort_order' => $sortOrder,
        ]);
    }

    public function test_reorder_rewrites_every_assets_sort_order_to_the_given_position(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $a = $this->asset($website, 0);
        $b = $this->asset($website, 1);
        $c = $this->asset($website, 2);

        app(WebsiteGalleryManager::class)->reorder($website, [$c->uid, $a->uid, $b->uid]);

        $this->assertSame(0, $c->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    public function test_reorder_refuses_a_list_missing_one_of_the_websites_assets(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $a = $this->asset($website, 0);
        $this->asset($website, 1);

        $this->expectException(\App\Exceptions\Website\InvalidWebsiteAssetException::class);
        app(WebsiteGalleryManager::class)->reorder($website, [$a->uid]);
    }

    public function test_reorder_refuses_a_foreign_assets_uid(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        [, $otherBusiness] = $this->entitledTenant();
        $otherWebsite = $this->createWebsite($otherBusiness);
        $foreign = $this->asset($otherWebsite, 0);
        $mine = $this->asset($website, 0);

        $this->expectException(\App\Exceptions\Website\InvalidWebsiteAssetException::class);
        app(WebsiteGalleryManager::class)->reorder($website, [$foreign->uid]);
    }

    public function test_setting_a_cover_clears_any_prior_cover(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $a = $this->asset($website, 0);
        $b = $this->asset($website, 1);
        $manager = app(WebsiteGalleryManager::class);

        $manager->setCover($website, $a);
        $this->assertTrue($a->fresh()->is_cover);

        $manager->setCover($website, $b);
        $this->assertFalse($a->fresh()->is_cover, 'Setting a new cover must clear the prior one — only one cover at a time.');
        $this->assertTrue($b->fresh()->is_cover);
    }

    public function test_setting_a_cover_on_a_foreign_asset_is_refused(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        [, $otherBusiness] = $this->entitledTenant();
        $otherWebsite = $this->createWebsite($otherBusiness);
        $foreign = $this->asset($otherWebsite, 0);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(WebsiteGalleryManager::class)->setCover($website, $foreign);
    }
}
