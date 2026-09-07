<?php

namespace Tests\Feature\Website;

use App\Events\Website\WebsitePublished;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.3 (§9.2/§10) — the
 * WebsitePublished event: dispatched exactly once per successful
 * publish/rollback, carrying the correct (websiteId, websiteRevisionId,
 * businessId) triple, never dispatched when the transaction fails
 * validation before completing, and — because the event implements
 * ShouldDispatchAfterCommit — only ever observed by a listener once the
 * wrapping transaction has actually committed.
 */
class WebsitePublishedEventTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_publishing_dispatches_website_published_exactly_once_with_correct_payload(): void
    {
        Event::fake([WebsitePublished::class]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $website->refresh();
        $revision = WebsiteRevision::where('website_id', $website->id)->latest('version_number')->first();

        Event::assertDispatchedTimes(WebsitePublished::class, 1);
        Event::assertDispatched(WebsitePublished::class, function (WebsitePublished $event) use ($website, $revision, $business) {
            return $event->websiteId === $website->id
                && $event->websiteRevisionId === $revision->id
                && $event->businessId === $business->id;
        });
    }

    public function test_publish_attempt_with_zero_pages_does_not_dispatch_website_published(): void
    {
        Event::fake([WebsitePublished::class]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        // Deliberately no pages — WebsitePublisher::validateDraft() throws
        // a ValidationException before ever reaching the dispatch line.
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertSessionHasErrors('website');

        Event::assertNotDispatched(WebsitePublished::class);
    }

    public function test_successful_rollback_dispatches_website_published_with_the_earlier_target_revision_id(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->authenticateAsCustomer($customer);

        // First publish creates revision 1.
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();
        $firstRevision = WebsiteRevision::where('website_id', $website->id)->orderBy('version_number')->first();

        // Change the draft and publish again to create revision 2, which
        // becomes the currently-live revision.
        $website->refresh();
        $website->pages()->first()->update(['sections' => [$this->section('text')]]);

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();
        $secondRevision = WebsiteRevision::where('website_id', $website->id)->orderByDesc('version_number')->first();

        $this->assertNotEquals($firstRevision->id, $secondRevision->id);

        $website->refresh();
        $this->assertSame($secondRevision->id, $website->published_revision_id);

        Event::fake([WebsitePublished::class]);

        $this->post(route('customer.workspaces.businesses.website.history.rollback', [$workspace->uid, $business->uid, $firstRevision->uid]))
            ->assertRedirect();

        $website->refresh();
        $this->assertSame($firstRevision->id, $website->published_revision_id);

        Event::assertDispatchedTimes(WebsitePublished::class, 1);
        Event::assertDispatched(WebsitePublished::class, function (WebsitePublished $event) use ($website, $firstRevision, $secondRevision, $business) {
            return $event->websiteId === $website->id
                && $event->websiteRevisionId === $firstRevision->id
                && $event->websiteRevisionId !== $secondRevision->id
                && $event->businessId === $business->id;
        });
    }
}
