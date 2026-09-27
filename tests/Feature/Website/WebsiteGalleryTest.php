<?php

namespace Tests\Feature\Website;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The `gallery` Website Component Library section (contract §7.2 family,
 * extended). A gallery section is never pre-seeded by the platform — it
 * only ever exists once the owner has uploaded real photos and picked
 * them, through the same generic page editor and WebsiteSectionValidator
 * seam every other section type already uses. These tests prove the
 * multi-photo case end-to-end: upload, save, publish, and public render,
 * plus that a gallery-referenced asset is protected exactly like any
 * other referenced asset.
 */
class WebsiteGalleryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_a_multi_photo_gallery_page_saves_publishes_and_renders_every_photo_publicly(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('event-1.png'),
            'alt_text' => 'Guests at the mirror booth',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('event-2.png'),
            'alt_text' => 'Printed photo strips on display',
        ])->assertRedirect()->assertSessionHasNoErrors();

        [$assetOne, $assetTwo] = $website->assets()->orderBy('id')->get();

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [$this->section('hero')],
        ])->assertRedirect();

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Gallery',
            'slug' => 'gallery',
            'is_home' => 0,
            'sections' => [
                $this->section('hero', ['heading' => 'Event gallery']),
                $this->section('gallery', ['heading' => 'Recent events', 'items' => [
                    ['image' => $assetOne->uid],
                    ['image' => $assetTwo->uid],
                ]]),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $website->refresh();

        $response = $this->get(route('public.website.page', [$website->public_id, 'gallery']))
            ->assertOk()
            ->assertSee('Event gallery')
            ->assertSee('Recent events')
            ->assertSee($assetOne->fresh()->url(), false)
            ->assertSee('Guests at the mirror booth', false)
            ->assertSee($assetTwo->fresh()->url(), false)
            ->assertSee('Printed photo strips on display', false);

        $response->assertSeeInOrder([$assetOne->url(), $assetTwo->url()], false);
    }

    public function test_asset_referenced_only_by_a_gallery_section_is_protected_from_deletion_once_published(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('gallery-only.png'),
            'alt_text' => 'Backdrop set up for a wedding',
        ])->assertRedirect();

        $asset = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [
                $this->section('hero'),
                $this->section('gallery', ['items' => [['image' => $asset->uid]]]),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($asset->fresh()->first_published_at);

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->assertNotNull($asset->fresh()->first_published_at);

        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $asset->uid]))
            ->assertSessionHasErrors('asset');

        $this->assertDatabaseHas('website_assets', ['id' => $asset->id]);
    }

    public function test_gallery_section_type_is_offered_in_the_page_editor(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.pages.create', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('<option value="gallery">Gallery</option>', false);
    }
}
