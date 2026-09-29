<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsiteRevision;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generator + Local SEO Completion — "Rebuild website from
 * template" (task instruction). Proves the non-destructive contract:
 * the currently published revision stays live and unmodified through a
 * rebuild; only the mutable draft is replaced; the owner must still
 * explicitly publish; and rollback to the pre-rebuild revision still
 * works afterward.
 */
class WebsiteRebuildTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    public function test_rebuild_replaces_draft_pages_but_never_touches_the_published_revision(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Original Home']);
        $publisher = app(WebsitePublisher::class);
        $publishedRevision = $publisher->publish($website, $customer->user_id);

        $originalSnapshot = $publishedRevision->snapshot;
        $originalPublishedRevisionId = $website->fresh()->published_revision_id;

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        app(WebsiteStarterDraftService::class)->rebuildFromTemplate($business, $website->fresh(), $template);

        $website->refresh();

        // The published revision itself is content-for-content unchanged.
        // assertEquals (not assertSame): the `snapshot` column is a native
        // MySQL JSON column, and MySQL does not guarantee object key order
        // is preserved across a store/retrieve round trip — an in-memory
        // array compared against one freshly decoded from the DB can differ
        // in key order alone. That is a MySQL JSON storage artifact, not a
        // sign the rebuild touched the revision; content equality is what
        // "unchanged" actually means here.
        $this->assertSame($originalPublishedRevisionId, $website->published_revision_id);
        $this->assertEquals($originalSnapshot, WebsiteRevision::find($originalPublishedRevisionId)->snapshot);

        // The draft was genuinely replaced (the old single "Original
        // Home" page is gone; a new, fuller page set exists).
        $this->assertNull($website->pages()->where('title', 'Original Home')->first());
        $this->assertTrue($website->pages()->where('is_home', true)->exists());
        $this->assertSame($template->key, $website->template_key);
    }

    public function test_the_public_site_keeps_serving_the_old_content_until_the_owner_explicitly_republishes(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Original Home']);
        app(WebsitePublisher::class)->publish($website, $customer->user_id);

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_editorial');
        app(WebsiteStarterDraftService::class)->rebuildFromTemplate($business, $website->fresh(), $template);

        $response = $this->get(route('public.website.home', $website->fresh()->public_id));
        $response->assertOk()->assertSee('Original Home');
    }

    public function test_rollback_to_the_pre_rebuild_revision_still_works_after_a_rebuild_and_republish(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Original Home']);
        $publisher = app(WebsitePublisher::class);
        $firstRevision = $publisher->publish($website, $customer->user_id);

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_luxury');
        app(WebsiteStarterDraftService::class)->rebuildFromTemplate($business, $website->fresh(), $template);
        $publisher->publish($website->fresh(), $customer->user_id);

        $publisher->rollback($website->fresh(), $firstRevision->uid);

        $response = $this->get(route('public.website.home', $website->fresh()->public_id));
        $response->assertOk()->assertSee('Original Home');
    }
}
