<?php

namespace Tests\Feature\Website;

use App\Models\WebsiteAsset;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Contract §37.11 (Revision asset retention). Once an asset has appeared
 * in a successful publish (WebsiteAsset.first_published_at set,
 * contract §13.1), it can NEVER be deleted again — permanently, even
 * after every draft page and every later revision stops referencing it —
 * because an EARLIER revision's immutable snapshot still embeds it and
 * must keep rendering correctly if that revision is ever rolled back to.
 * A never-published asset has no such constraint once no current draft
 * page references it; a draft-only reference blocks deletion on its own,
 * independent of publish history.
 */
class WebsiteAssetRetentionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_asset_referenced_by_a_published_revision_is_retained_through_republish_rollback_and_still_renders_publicly(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        // (1) Upload Asset A, build a homepage whose hero references it,
        // and publish -> revision 1.
        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('asset-a.png'),
            'alt_text' => 'Asset A',
        ])->assertRedirect();

        $assetA = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [$this->section('hero', ['background_image' => $assetA->uid])],
        ])->assertRedirect();

        $page = $website->pages()->sole();

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $revisionOne = WebsiteRevision::where('website_id', $website->id)->sole();
        $this->assertSame(1, $revisionOne->version_number);
        $this->assertNotNull(WebsiteAsset::find($assetA->id)->first_published_at);

        // (2) Edit the draft homepage to remove the Asset A reference,
        // then publish again -> revision 2.
        $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [$this->section('hero', ['background_image' => null])],
        ])->assertRedirect();

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $revisionTwo = WebsiteRevision::where('website_id', $website->id)->where('version_number', 2)->sole();

        $this->assertStringNotContainsString($assetA->uid, json_encode($revisionTwo->snapshot));

        // Revision 1's own stored snapshot, fetched fresh, must still
        // embed Asset A untouched.
        $revisionOneFresh = WebsiteRevision::find($revisionOne->id);
        $this->assertStringContainsString($assetA->uid, json_encode($revisionOneFresh->snapshot));

        // (3) Deleting Asset A is rejected, and the row and file both
        // survive the attempt.
        $assetPath = public_path($assetA->path);
        $this->assertFileExists($assetPath);

        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $assetA->uid]))
            ->assertSessionHasErrors('asset');

        $this->assertDatabaseHas('website_assets', ['id' => $assetA->id]);
        $this->assertFileExists($assetPath);

        // (4) Roll back to revision 1.
        $this->post(route('customer.workspaces.businesses.website.history.rollback', [$workspace->uid, $business->uid, $revisionOne->uid]))
            ->assertRedirect();

        $website->refresh();
        $this->assertSame($revisionOne->id, $website->published_revision_id);

        // (5) The public homepage now renders revision 1's snapshot again
        // -- Asset A's URL must actually appear in the HTML, proving
        // retention enables correct rendering, not merely a rejected
        // delete in isolation.
        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee($assetA->fresh()->url(), false);
    }

    public function test_a_never_published_unreferenced_asset_can_be_deleted(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('asset-b.png'),
        ])->assertRedirect();

        $assetB = $website->assets()->sole();
        $this->assertNull($assetB->first_published_at);
        $assetPath = public_path($assetB->path);

        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $assetB->uid]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('website_assets', ['id' => $assetB->id]);
        $this->assertFileDoesNotExist($assetPath);
    }

    public function test_an_asset_referenced_by_a_current_draft_page_cannot_be_deleted_even_if_never_published(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('asset-c.png'),
        ])->assertRedirect();

        $assetC = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [$this->section('hero', ['background_image' => $assetC->uid])],
        ])->assertRedirect();

        // Never published — proves isReferencedByDraft() blocks deletion
        // on its own, independent of the first_published_at check.
        $this->assertNull($assetC->fresh()->first_published_at);

        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $assetC->uid]))
            ->assertSessionHasErrors('asset');

        $this->assertDatabaseHas('website_assets', ['id' => $assetC->id]);
        $this->assertNull($assetC->fresh()->first_published_at);
    }
}
