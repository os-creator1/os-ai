<?php

namespace App\Library\Seo;

use App\Models\Business;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Library\Workspace\LocationAccessGuard;
use Illuminate\Support\Collection;

/**
 * Contract 18 §6 / §10.4 — the actor's accessible Locations, resolved once
 * per request and handed to every SEO reader BEFORE it aggregates anything.
 *
 * This is deliberately a thin adapter, not an authority. Location access is
 * decided by LocationAccessGuard and only by it; there is no SEO-specific
 * access rule anywhere. It exists so that SEO code has ONE place that turns
 * "who is asking" into "which Locations may they see", using the guard's
 * constant-query bulk method rather than looping the per-Location check
 * (N+1) — which is what makes "query count independent of Location count"
 * (§11.4) achievable.
 *
 * THE AGGREGATION RULE this class exists to serve (§6, load-bearing):
 * filter to the actor's accessible Locations FIRST, aggregate SECOND, never
 * the reverse. A Selected-scope staff member must not be able to infer an
 * inaccessible Location's existence or state from a count.
 *
 * It never trusts a Business or Location model passed to it; the guard
 * re-derives everything from persistence.
 */
final class SeoLocationScope
{
    public function __construct(
        private readonly LocationAccessGuard $guard,
        private readonly BusinessLocationRepository $locations,
    ) {
    }

    /**
     * Every Location of $business this actor may access (any lifecycle
     * state), in BusinessLocationRepository::forBusiness() order.
     *
     * @return Collection<int, \App\Models\BusinessLocation>
     */
    public function accessibleLocations(int $userId, Business $business): Collection
    {
        $accessibleIds = array_flip($this->guard->accessibleLocationIdsForBusiness($userId, $business));

        if ($accessibleIds === []) {
            return new Collection();
        }

        return $this->locations->forBusiness($business)
            ->filter(fn ($location) => isset($accessibleIds[(int) $location->id]))
            ->values();
    }

    /**
     * The actor's accessible Locations AND every Location of the Business, from
     * ONE read of the Business's Locations — for a page that needs both (to know
     * whether the actor reaches them all) without a second query.
     *
     * @return array{accessible: Collection<int, \App\Models\BusinessLocation>, all: Collection<int, \App\Models\BusinessLocation>}
     */
    public function reach(int $userId, Business $business): array
    {
        $accessibleIds = array_flip($this->guard->accessibleLocationIdsForBusiness($userId, $business));
        $all = $this->locations->forBusiness($business);

        return [
            'accessible' => $all->filter(fn ($location) => isset($accessibleIds[(int) $location->id]))->values(),
            'all' => $all,
        ];
    }

    /**
     * True only when the actor may access EVERY Location of the Business (any
     * lifecycle state). Something that applies to all of a Business's Locations
     * — a Business-wide custom citation directory — may be changed only by an
     * actor with that reach: a user restricted to some Locations must not
     * change what the Locations they cannot reach see.
     */
    public function accessesEveryLocation(int $userId, Business $business): bool
    {
        $accessible = $this->guard->accessibleLocationIdsForBusiness($userId, $business);
        $all = $this->locations->forBusiness($business)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $all !== [] && array_diff($all, $accessible) === [];
    }

    /**
     * The accessible Locations that are still operational. Location-bound
     * SEO WRITES (later sub-slices) require this; Archived Locations keep
     * their history visible read-only (Contract 18 §10.5).
     *
     * @return Collection<int, \App\Models\BusinessLocation>
     */
    public function accessibleActiveLocations(int $userId, Business $business): Collection
    {
        return $this->accessibleLocations($userId, $business)
            ->filter(fn ($location) => $location->isActive())
            ->values();
    }
}
