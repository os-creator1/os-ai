<?php

namespace Tests\Feature\Website;

use App\Models\Business;
use App\Models\Website;
use App\Models\Workspace;
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

    // ---------------------------------------------------------------
    // Guided flow: upload from the Website area, select, create the
    // Gallery page in one action. Uploading never attaches a photo to
    // any page by itself.
    // ---------------------------------------------------------------

    public function test_the_photos_screen_shows_an_empty_state_when_no_photos_are_uploaded_yet(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.photos.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('No photos yet')
            ->assertDontSee('Create Gallery page')
            ->assertDontSee('name="asset_uids[]"', false);
    }

    public function test_uploading_a_photo_from_the_website_area_does_not_attach_it_to_any_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('unselected.png'),
            'alt_text' => 'Not yet chosen for anything',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $asset = $website->assets()->sole();

        $this->get(route('customer.workspaces.businesses.website.photos.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Create Gallery page')
            ->assertSee($asset->url(), false);

        // Freely deletable: uploading alone never creates a page reference.
        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $asset->uid]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('website_assets', ['id' => $asset->id]);
    }

    public function test_uploading_a_photo_requires_alt_text(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('no-description.png'),
        ])->assertSessionHasErrors('alt_text');

        $this->assertSame(0, $website->assets()->count());
    }

    public function test_selecting_photos_creates_a_gallery_page_that_publishes_and_renders_publicly(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('booth-1.png'),
            'alt_text' => 'Guests laughing in the booth',
        ])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('booth-2.png'),
            'alt_text' => 'Printed strips fanned out on a table',
        ])->assertRedirect();

        [$assetOne, $assetTwo] = $website->assets()->orderBy('id')->get();

        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$assetOne->uid, $assetTwo->uid],
        ])->assertRedirect(route('customer.workspaces.businesses.website.photos.index', [$workspace->uid, $business->uid]))
            ->assertSessionHasNoErrors();

        $galleryPage = $website->pages()->where('slug', 'gallery')->firstOrFail();
        $this->assertSame('Gallery', $galleryPage->title);
        $this->assertTrue($galleryPage->noindex);
        $gallerySection = collect($galleryPage->sections)->firstWhere('type', 'gallery');
        $this->assertSame([$assetOne->uid, $assetTwo->uid], collect($gallerySection['data']['items'])->pluck('image')->all());

        // Now that they're selected, both photos are locked in by the
        // normal draft-reference rule — proving the selection, not the
        // earlier upload, is what attaches them.
        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $assetOne->uid]))
            ->assertSessionHasErrors('asset');

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->get(route('public.website.page', [$website->fresh()->public_id, 'gallery']))
            ->assertOk()
            ->assertSee($assetOne->url(), false)
            ->assertSee('Guests laughing in the booth', false)
            ->assertSee($assetTwo->url(), false)
            ->assertSee('Printed strips fanned out on a table', false);
    }

    public function test_selecting_different_photos_later_updates_the_existing_gallery_page_in_place(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('first.png'),
            'alt_text' => 'First upload',
        ])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('second.png'),
            'alt_text' => 'Second upload',
        ])->assertRedirect();

        [$first, $second] = $website->assets()->orderBy('id')->get();

        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$first->uid],
        ])->assertRedirect();

        $this->assertSame(1, $website->pages()->where('slug', 'gallery')->count());
        $galleryPageId = $website->pages()->where('slug', 'gallery')->value('id');

        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$second->uid],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Still exactly one Gallery page — updated in place, not duplicated.
        $this->assertSame(1, $website->pages()->where('slug', 'gallery')->count());
        $galleryPage = $website->pages()->findOrFail($galleryPageId);
        $gallerySection = collect($galleryPage->sections)->firstWhere('type', 'gallery');
        $this->assertSame([$second->uid], collect($gallerySection['data']['items'])->pluck('image')->all());

        // The now-unselected first photo was never referenced again, so
        // it remains freely deletable.
        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $first->uid]))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_gallery_store_rejects_a_foreign_or_unknown_asset_uid(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('mine.png'),
            'alt_text' => 'My own upload',
        ])->assertRedirect();

        $mine = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$mine->uid, 'not-a-real-asset-uid'],
        ])->assertSessionHasErrors('asset_uids');

        $this->assertSame(0, $website->pages()->where('slug', 'gallery')->count());
    }

    public function test_the_new_page_editor_offers_previously_uploaded_photos(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('already-uploaded.png'),
            'alt_text' => 'Already uploaded before this page existed',
        ])->assertRedirect();

        $this->get(route('customer.workspaces.businesses.website.pages.create', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Already uploaded before this page existed', false);
    }

    // ---------------------------------------------------------------
    // Reusing the Gallery page's own selected photos on another page
    // (e.g. Photo Booth Services or Packages) — never a second
    // selection UI, never a photo the owner has not already chosen.
    // ---------------------------------------------------------------

    private function selectGalleryPhotos(Website $website, Business $business, Workspace $workspace): array
    {
        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('reuse-1.png'),
            'alt_text' => 'Booth setup at a wedding',
        ])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('reuse-2.png'),
            'alt_text' => 'Props table at a corporate event',
        ])->assertRedirect();

        [$assetOne, $assetTwo] = $website->assets()->orderBy('id')->get();

        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$assetOne->uid, $assetTwo->uid],
        ])->assertRedirect();

        return [$assetOne, $assetTwo];
    }

    public function test_reusing_gallery_photos_adds_a_gallery_section_to_another_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        [$assetOne, $assetTwo] = $this->selectGalleryPhotos($website, $business, $workspace);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Services',
            'slug' => 'photo-booth-services',
            'is_home' => 0,
            'sections' => [$this->section('hero', ['heading' => 'Photo booth services'])],
        ])->assertRedirect();
        $servicesPage = $website->pages()->where('slug', 'photo-booth-services')->firstOrFail();

        $this->post(route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [$workspace->uid, $business->uid, $servicesPage->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $servicesPage->uid]))
            ->assertSessionHasNoErrors();

        $sections = $servicesPage->fresh()->sections;
        // The original hero section is untouched, and the new gallery
        // section carries exactly the photos already selected for the
        // Gallery page — not a fresh, separate selection.
        $this->assertSame('hero', $sections[0]['type']);
        $this->assertSame('Photo booth services', $sections[0]['data']['heading']);
        $gallerySection = collect($sections)->firstWhere('type', 'gallery');
        $this->assertNotNull($gallerySection);
        $this->assertSame([$assetOne->uid, $assetTwo->uid], collect($gallerySection['data']['items'])->pluck('image')->all());
    }

    public function test_reusing_gallery_photos_again_replaces_the_pages_own_gallery_section_in_place(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('stale.png'),
            'alt_text' => 'A photo picked directly on this page before',
        ])->assertRedirect();
        $stale = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Packages',
            'slug' => 'photo-booth-packages',
            'is_home' => 0,
            'sections' => [
                $this->section('hero', ['heading' => 'Photo booth packages']),
                $this->section('gallery', ['items' => [['image' => $stale->uid]]]),
            ],
        ])->assertRedirect();
        $packagesPage = $website->pages()->where('slug', 'photo-booth-packages')->firstOrFail();

        [$assetOne, $assetTwo] = $this->selectGalleryPhotos($website, $business, $workspace);

        $this->post(route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [$workspace->uid, $business->uid, $packagesPage->uid]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $sections = $packagesPage->fresh()->sections;
        // Still exactly one gallery section on the page (updated in
        // place), and the hero section before it is untouched.
        $this->assertSame(2, count($sections));
        $this->assertSame('hero', $sections[0]['type']);
        $gallerySection = collect($sections)->firstWhere('type', 'gallery');
        $this->assertSame([$assetOne->uid, $assetTwo->uid], collect($gallerySection['data']['items'])->pluck('image')->all());
    }

    public function test_reusing_gallery_photos_is_refused_before_any_photos_are_selected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Services',
            'slug' => 'photo-booth-services',
            'is_home' => 0,
            'sections' => [$this->section('hero')],
        ])->assertRedirect();
        $servicesPage = $website->pages()->where('slug', 'photo-booth-services')->firstOrFail();

        $this->post(route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [$workspace->uid, $business->uid, $servicesPage->uid]))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertSame(['hero'], array_column($servicesPage->fresh()->sections, 'type'));
    }

    public function test_reusing_gallery_photos_onto_a_foreign_websites_page_404s(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->authenticateAsCustomer($customerA);
        $this->selectGalleryPhotos($websiteA, $businessA, $workspaceA);

        [, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $foreignPage = $this->homePage($websiteB);

        $this->post(route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [$workspaceA->uid, $businessA->uid, $foreignPage->uid]))
            ->assertNotFound();
    }
}
