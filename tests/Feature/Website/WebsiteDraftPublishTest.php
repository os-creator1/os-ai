<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteStatus;
use App\Library\Website\WebsitePublisher;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Contract §37.3 (Draft/publish) — proves the publish/rollback DATA
 * behavior: the public renderer only ever reads an immutable
 * WebsiteRevision snapshot, a draft edit after publish never leaks into
 * what is currently live, each publish() appends a new revision without
 * mutating any earlier one, rollback repoints the pointer without
 * touching revision rows, and the public gate excludes both a
 * never-published Website and an archived one. WebsitePublished event
 * dispatch itself is out of scope here (covered elsewhere).
 */
class WebsiteDraftPublishTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_draft_edit_after_publish_does_not_change_what_the_public_renderer_serves(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Original Title',
            'is_home' => 1,
            'sections' => [$this->section('hero')],
        ])->assertRedirect();

        $page = $website->pages()->firstOrFail();

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Original Title');

        $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), [
            'title' => 'Updated Title',
            'is_home' => 1,
            'sections' => [$this->section('hero')],
        ])->assertRedirect();

        $this->assertSame('Updated Title', $page->fresh()->title);

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Original Title')
            ->assertDontSee('Updated Title');
    }

    public function test_first_publish_creates_exactly_one_revision_matching_the_draft(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business, ['name' => 'Acme Co']);
        $this->homePage($website, ['title' => 'Home Page']);

        $revision = app(WebsitePublisher::class)->publish($website, $customer->user->id);

        $this->assertSame(1, WebsiteRevision::where('website_id', $website->id)->count());
        $this->assertSame(1, $revision->version_number);
        $this->assertSame(1, $revision->snapshot['schema_version']);
        $this->assertSame('Acme Co', $revision->snapshot['website']['name']);
        $this->assertCount(1, $revision->snapshot['pages']);
        $this->assertSame('Home Page', $revision->snapshot['pages'][0]['title']);
        $this->assertTrue($revision->snapshot['pages'][0]['is_home']);
    }

    public function test_second_publish_creates_version_two_and_leaves_the_first_revision_snapshot_untouched(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Home Page']);

        $publisher = app(WebsitePublisher::class);
        $firstRevision = $publisher->publish($website, $customer->user->id);
        $firstId = $firstRevision->id;
        $originalSnapshot = WebsiteRevision::find($firstId)->snapshot;

        // Further draft edit before the second publish.
        $this->subPage($website, 'about');

        $secondRevision = $publisher->publish($website->fresh(), $customer->user->id);

        $this->assertSame(2, $secondRevision->version_number);
        $this->assertNotSame($firstId, $secondRevision->id);
        $this->assertSame($originalSnapshot, WebsiteRevision::find($firstId)->snapshot);
        $this->assertCount(1, $originalSnapshot['pages']);
        $this->assertCount(2, $secondRevision->snapshot['pages']);
    }

    public function test_public_renderer_reads_only_the_published_snapshot_never_the_live_draft_row(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $page = $this->homePage($website, ['title' => 'Home Page']);

        app(WebsitePublisher::class)->publish($website, $customer->user->id);

        DB::table('website_pages')->where('id', $page->id)->update(['title' => 'MUTATED-DIRECTLY']);

        $this->get(route('public.website.home', $website->fresh()->public_id))
            ->assertOk()
            ->assertSee('Home Page')
            ->assertDontSee('MUTATED-DIRECTLY');
    }

    public function test_rollback_repoints_the_website_without_mutating_any_revision_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Version One']);

        $publisher = app(WebsitePublisher::class);
        $revisionOne = $publisher->publish($website, $customer->user->id);

        $this->subPage($website, 'about');
        $revisionTwo = $publisher->publish($website->fresh(), $customer->user->id);

        // Captured by RE-READING the persisted rows, not from the models
        // returned by publish(). `snapshot` is a MySQL `json` column and
        // MySQL normalises the key order of every object it stores, so an
        // in-memory snapshot never equals its own read-back and this
        // comparison failed for a reason unrelated to mutation.
        //
        // Reading both sides from the database makes this a genuine
        // byte-for-byte before/after comparison of the PERSISTED rows —
        // strictly stronger than the previous check, because it now also
        // catches a mutation that happened to round-trip back to the
        // original in-memory shape.
        $readPersisted = static function (int $id): array {
            $row = WebsiteRevision::findOrFail($id);

            return [$row->snapshot, $row->version_number, $row->created_at->toIso8601String()];
        };

        $before = [
            $revisionOne->id => $readPersisted($revisionOne->id),
            $revisionTwo->id => $readPersisted($revisionTwo->id),
        ];

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.history.rollback', [$workspace->uid, $business->uid, $revisionOne->uid]))
            ->assertRedirect();

        $website->refresh();
        $this->assertSame($revisionOne->id, $website->published_revision_id);
        $this->assertSame(WebsiteStatus::Published, $website->status);

        foreach ($before as $id => [$snapshot, $versionNumber, $createdAt]) {
            $fresh = WebsiteRevision::find($id);
            $this->assertSame($snapshot, $fresh->snapshot);
            $this->assertSame($versionNumber, $fresh->version_number);
            $this->assertSame($createdAt, $fresh->created_at->toIso8601String());
        }
    }

    public function test_never_published_website_404s_on_every_public_route_despite_having_draft_content(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');

        $this->assertSame(WebsiteStatus::Draft, $website->status);
        $this->assertNull($website->published_revision_id);

        $this->get(route('public.website.home', $website->public_id))->assertNotFound();
        $this->get(route('public.website.page', [$website->public_id, 'about']))->assertNotFound();
        $this->get(route('public.website.sitemap', $website->public_id))->assertNotFound();
    }

    public function test_archived_website_404s_publicly_even_though_a_published_revision_still_exists(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Home Page']);

        app(WebsitePublisher::class)->publish($website, $customer->user->id);
        $website->refresh();
        $this->assertNotNull($website->published_revision_id);

        DB::table('websites')->where('id', $website->id)->update(['status' => WebsiteStatus::Archived->value]);

        $this->get(route('public.website.home', $website->fresh()->public_id))->assertNotFound();
    }
}
