<?php

namespace App\Listeners\Seo;

use App\Events\Website\WebsitePublished;
use App\Jobs\Seo\RunSeoAuditForRevision;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 18 §8.7, Sub-slice G — queue a technical audit for the revision a
 * publish (or rollback) just made live.
 *
 * ADDITIVE. `WebsitePublished` already has one listener
 * (InvalidateCooInsights); this one is registered alongside it and changes
 * nothing about publishing. Website publication stays authoritative and
 * entirely independent of SEO.
 *
 * A PUBLISH MUST NEVER FAIL BECAUSE THE AUDIT COULD NOT BE QUEUED. Two
 * mechanisms, deliberately both:
 *
 *  1. `WebsitePublished` is `ShouldDispatchAfterCommit`, so this listener runs
 *     only after the publish transaction has committed. Nothing it does can
 *     roll a publish back — the publish is already durable before we are
 *     called.
 *  2. Everything here is wrapped and swallowed. Even after commit, an
 *     exception escaping a synchronous listener would propagate into the
 *     request that published and show the customer a failure for work that
 *     actually succeeded. A queue backend that is down must cost the customer
 *     an audit, never their publish.
 *
 * The listener itself is synchronous and does exactly one cheap thing — push
 * the real work onto the queue. Keeping the guard here rather than inside a
 * queued listener is what makes mechanism 2 meaningful: the failure we must
 * absorb is the enqueue itself.
 *
 * It audits the revision the event NAMES, not "the currently published one":
 * by the time the worker runs, a newer revision may already be live, and
 * auditing that one would attribute its findings to the wrong publish.
 */
class QueueSeoAuditOnWebsitePublished
{
    /**
     * The bus is injected rather than reached through the `dispatch()` helper
     * so the enqueue happens INSIDE the try below, where it can be caught. A
     * `PendingDispatch` would instead enqueue from its destructor, outside
     * this method's control — and a failure there is exactly the one that
     * must never reach the customer's publish.
     */
    public function __construct(private readonly Dispatcher $bus)
    {
    }

    public function handle(WebsitePublished $event): void
    {
        try {
            $this->bus->dispatch(new RunSeoAuditForRevision(
                $event->businessId,
                $event->websiteId,
                $event->websiteRevisionId,
            ));
        } catch (Throwable $e) {
            // Deliberately swallowed — see the class docblock.
            Log::warning('Could not queue the SEO audit for a published revision', [
                'business_id' => $event->businessId,
                'website_id' => $event->websiteId,
                'website_revision_id' => $event->websiteRevisionId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
