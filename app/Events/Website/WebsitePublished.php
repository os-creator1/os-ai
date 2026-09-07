<?php

namespace App\Events\Website;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Website Generation + Hosting Slice A contract §9.2/§10 — a narrow
 * predecessor seam for a future, separately contracted SEO module.
 * Carries exactly three stable scalar identifiers, never a hydrated
 * model or a snapshot payload. Dispatched on both a forward publish and
 * a rollback (contract §10's locked decision — from this event's own
 * perspective a rollback is indistinguishable from any other change of
 * the live revision).
 *
 * ShouldDispatchAfterCommit (the same mechanism
 * App\Events\Entitlement\BusinessFeatureToggleChanged already uses in
 * this codebase) means the event can be dispatched from inside the
 * publish/rollback DB transaction and Laravel itself defers actually
 * running any listener until that transaction commits — no listener
 * ever observes this event for a publish/rollback that gets rolled back.
 * Slice A ships zero listeners for this event (contract §36) — it exists
 * to be dispatched, not consumed, in this feature.
 */
class WebsitePublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $websiteId,
        public readonly int $websiteRevisionId,
        public readonly int $businessId,
    ) {
    }
}
