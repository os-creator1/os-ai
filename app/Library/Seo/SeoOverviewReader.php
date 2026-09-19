<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoIndexabilityState;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;

/**
 * Contract 18 §9.3 — composes the SEO Overview for one already-resolved
 * Workspace / Business / actor.
 *
 * ZERO EXTERNAL CALLS, in every state and for every tier: this reads only
 * platform-owned tables. It makes no HTTP request, calls no provider client,
 * dispatches no job, calls no AI, and performs no write anywhere.
 *
 * ORDER IS THE RULE (Contract 18 §6): the actor's accessible Locations are
 * resolved FIRST (SeoLocationScope, backed by LocationAccessGuard) and
 * everything Location-shaped is computed from that already-filtered set, so
 * an inaccessible Location can never influence a count.
 *
 * It presumes the caller has already run the mandatory tenancy chain,
 * the `view_seo` capability gate and the entitlement decision; it is a
 * reader, not an authority.
 *
 * Sub-slice 18A populates only the Core sections plus the GBP status
 * summary (which appears only for an actor GBP itself entitles and permits).
 * Sections belonging to later sub-slices do not exist yet and do not render.
 */
final class SeoOverviewReader
{
    public function __construct(
        private readonly SeoLocationScope $locationScope,
        private readonly SeoPublishedContentReader $publishedContent,
        private readonly SeoReadinessRuleRegistry $readinessRules,
        private readonly GoogleBusinessProfileStatusReader $googleStatus,
    ) {
    }

    public function read(Workspace $workspace, Business $business, User $actor): SeoOverview
    {
        $accessibleActiveLocations = $this->locationScope->accessibleActiveLocations((int) $actor->id, $business);

        $published = $this->publishedContent->forBusiness($business);

        $facts = SeoReadinessFacts::build($business, $published !== null, $accessibleActiveLocations);

        return new SeoOverview(
            readiness: $this->readinessRules->evaluate($facts),
            content: $published === null ? null : [
                'pages' => $published->pageCount(),
                'with_meta_description' => $published->pagesWithMetaDescription(),
                'with_seo_title' => $published->pagesWithSeoTitle(),
                'marked_noindex' => $published->pagesMarkedNoindex(),
            ],
            indexability: $published === null
                ? SeoIndexabilityState::NoPublishedWebsite
                : SeoIndexabilityState::PlatformPathNotIndexable,
            google: $this->googleStatus->forBusiness($workspace, $business, $actor),
        );
    }
}
