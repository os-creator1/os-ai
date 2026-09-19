<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleLocationStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;
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

        $connection = $this->connections->findForBusiness($business);
        $bindingsByLocation = $this->bindings->allForBusinessKeyedByLocationId($business);

        $statuses = [];

        foreach ($accessibleLocations as $location) {
            $binding = $bindingsByLocation->get((int) $location->id);
            $fresh = $binding !== null && $binding->mirrorIsFresh();
            $mirror = $fresh ? $binding->freshMirror() : [];

            $statuses[] = new GoogleLocationStatus(
                locationId: (int) $location->id,
                locationUid: (string) $location->uid,
                locationName: (string) $location->name,
                bound: $binding !== null,
                connectionState: $connection?->state?->value,
                health: $binding?->verification_state,
                mirrorIsFresh: $fresh,
                newReviewUri: $fresh && is_string($mirror['new_review_uri'] ?? null) ? $mirror['new_review_uri'] : null,
                napMismatchCount: $fresh ? $this->mismatchCount($business, $location, $binding) : null,
            );
        }

        return $statuses;
    }

    private function mismatchCount(Business $business, $location, $binding): int
    {
        $count = 0;

        foreach ($this->comparator->compare($business, $location, $binding) as $row) {
            if ($row->status === GoogleComparisonStatus::Mismatch) {
                $count++;
            }
        }

        return $count;
    }
}
