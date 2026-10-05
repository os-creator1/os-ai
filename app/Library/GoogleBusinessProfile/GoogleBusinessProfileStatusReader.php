<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleLocationStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessGoogleConnectionRepository;
use App\Repositories\Contracts\BusinessGoogleLocationRepository;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Support\Facades\Gate;

/**
 * Contract 18 §9.2 — the read-only, Business-scoped status query service
 * GBP contract §37.2 promised SEO ("SEO may later consume a read-only,
 * Business-scoped query service … recorded so SEO does not invent its own
 * path"). It is the ONLY route by which SEO learns anything about GBP.
 *
 * Guarantees, each proven by test:
 *
 *  - READ-ONLY. It performs no write and NO PROVIDER CALL. It has no
 *    dependency on GoogleBusinessProfileReadClient, no HTTP client, no
 *    queue, no job dispatch, and no cache. It never persists what it reads.
 *  - GATED. It returns null unless the actor holds
 *    `view_google_business_profile` AND the Business is entitled to
 *    PlatformFeature::GoogleBusinessProfileModule. A Core Business, a
 *    disabled feature or an unpermitted actor gets null — never an empty
 *    list that would confirm the module exists.
 *  - LOCATION-ACL FILTERED BEFORE READING. Only Locations the actor may
 *    access (LocationAccessGuard, bulk form) are read or returned; an
 *    inaccessible Location's binding, health and mismatch count are never
 *    loaded into a result, so no aggregate can leak them.
 *  - EXPIRED MIRROR = ABSENT. The mirror-derived fields (review link,
 *    mismatch count) are null unless BusinessGoogleLocation::mirrorIsFresh().
 *    An expired or purged mirror is never rendered as current (GBP §13).
 *  - HEALTH CARRIES ITS AGE. `health` outlives the mirror, so every status also
 *    says when it was last synced (`healthAsOf`) and whether that is outside
 *    the freshness window (`healthIsStale`); a consumer must not print a
 *    health word without the age. Computed at read time from stored
 *    timestamps — no provider request.
 *  - CONSTANT QUERY COUNT. Independent of the number of Locations.
 *
 * It lives in the GBP namespace because GBP owns Google state. It is purely
 * additive: no existing GBP file is modified, and it depends on nothing in
 * the SEO module (no circular dependency, GBP §37.2).
 */
final class GoogleBusinessProfileStatusReader
{
    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly LocationAccessGuard $locationGuard,
        private readonly BusinessLocationRepository $locations,
        private readonly BusinessGoogleConnectionRepository $connections,
        private readonly BusinessGoogleLocationRepository $bindings,
        private readonly GoogleBusinessProfileComparator $comparator,
    ) {
    }

    /**
     * @return array<int, GoogleLocationStatus>|null null when the actor may not
     *         see GBP at all (no permission, or not entitled); otherwise one
     *         status per ACCESSIBLE Location, possibly empty.
     */
    public function forBusiness(Workspace $workspace, Business $business, User $actor): ?array
    {
        if (! Gate::forUser($actor)->allows('view_google_business_profile')) {
            return null;
        }

        try {
            $decision = $this->entitlements->decide(
                $workspace,
                $business,
                PlatformFeature::GoogleBusinessProfileModule->value,
                (int) $actor->id,
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return null;
        }

        if (! $decision->allowed) {
            return null;
        }

        $accessibleIds = array_flip($this->locationGuard->accessibleLocationIdsForBusiness((int) $actor->id, $business));

        if ($accessibleIds === []) {
            return [];
        }

        $accessibleLocations = $this->locations->forBusiness($business)
            ->filter(fn ($location) => isset($accessibleIds[(int) $location->id]))
            ->values();

        // This reader is GBP-only (contract §37.2): SEO's read is scoped to
        // business_profile until a future slice adds Search Console.
        $connection = $this->connections->findForBusiness($business, GoogleConnectionProduct::BusinessProfile);
        $bindingsByLocation = $this->bindings->allForBusinessKeyedByLocationId($business);

        $statuses = [];

        foreach ($accessibleLocations as $location) {
            $binding = $bindingsByLocation->get((int) $location->id);
            $fresh = $binding !== null && $binding->mirrorIsFresh();
            $mirror = $fresh ? $binding->freshMirror() : [];
            $mismatchFields = $fresh ? $this->mismatchFields($business, $location, $binding) : [];
            $healthAsOf = $binding?->last_synced_at ?? $binding?->mirror_fetched_at;

            $statuses[] = new GoogleLocationStatus(
                locationId: (int) $location->id,
                locationUid: (string) $location->uid,
                locationName: (string) $location->name,
                bound: $binding !== null,
                connectionState: $connection?->state?->value,
                health: $binding?->verification_state,
                mirrorIsFresh: $fresh,
                newReviewUri: $fresh && is_string($mirror['new_review_uri'] ?? null) ? $mirror['new_review_uri'] : null,
                napMismatchCount: $fresh ? count($mismatchFields) : null,
                mirrorName: $fresh && is_string($mirror['title'] ?? null) ? $mirror['title'] : null,
                mirrorPhone: $fresh && is_string($mirror['phone_primary'] ?? null) ? $mirror['phone_primary'] : null,
                mirrorWebsite: $fresh && is_string($mirror['website_uri'] ?? null) ? $mirror['website_uri'] : null,
                healthAsOf: $healthAsOf,
                healthIsStale: $binding !== null && (! $fresh || $healthAsOf === null || $healthAsOf->lt(now()->subDays(GoogleBusinessProfileRetention::MAX_MIRROR_RETENTION_DAYS))),
                napMismatchFields: $mismatchFields,
            );
        }

        return $statuses;
    }

    /**
     * Business-scoped, ACTOR-LESS read of the fresh Google "new review" link
     * per bound Location id, for a system consumer that has no signed-in user
     * (the Growth Center). Gated by the Business's entitlement to the GBP
     * module exactly like forBusiness(); there is no actor, so no Location ACL
     * is applied — the caller reports only on Locations it already owns. Fresh
     * mirror only (expired = absent), no provider call, nothing stored.
     *
     * @return array<int, string> keyed by business_locations.id
     */
    public function freshReviewLinks(Workspace $workspace, Business $business): array
    {
        try {
            $decision = $this->entitlements->decide($workspace, $business, PlatformFeature::GoogleBusinessProfileModule->value, 0);
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return [];
        }

        if (! $decision->allowed) {
            return [];
        }

        $links = [];

        foreach ($this->bindings->allForBusinessKeyedByLocationId($business) as $locationId => $binding) {
            $uri = $binding->mirrorIsFresh() ? ($binding->freshMirror()['new_review_uri'] ?? null) : null;

            if (is_string($uri) && $uri !== '') {
                $links[(int) $locationId] = $uri;
            }
        }

        return $links;
    }

    /**
     * The comparison rows that currently differ, by name — the rows the count
     * is made of, so the count is never shown without saying which.
     *
     * @return array<int, string>
     */
    private function mismatchFields(Business $business, $location, $binding): array
    {
        $fields = [];

        foreach ($this->comparator->compare($business, $location, $binding) as $row) {
            if ($row->status === GoogleComparisonStatus::Mismatch) {
                $fields[] = $row->field;
            }
        }

        return $fields;
    }
}
