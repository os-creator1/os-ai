<?php

namespace App\Library\Crm;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The Location axis of CRM LISTS — a thin adapter over the ONE canonical
 * LocationAccessGuard, adding no access algorithm of its own (the same shape as
 * BusinessEmailLocationScope, for the same reason).
 *
 * The single-record reads already ask the guard per row (Contract 08B). A LIST
 * must not: it takes the actor's reachable Location ids once and pushes them into
 * SQL, so a person who may not reach Location B never receives a Location B row to
 * filter client-side — not in a directory page, a board column, a picker, a bulk
 * selection or an export — and the cost does not grow with the number of rows.
 *
 * Semantics are Contract 08B's, unchanged: a row with a proven `location_id` must
 * be in the actor's reach (re-derived from persistence); a row whose Location was
 * never proven (`NULL`) is never denied on its own — the Business-level access the
 * caller already confirmed governs it.
 */
final class CrmLocationScope
{
    public function __construct(private readonly LocationAccessGuard $guard)
    {
    }

    /**
     * The ACTIVE Locations of the Business the actor may create Location-bound
     * records at (Contract 02 reach, via the one LocationAccessGuard).
     *
     * @return Collection<int, BusinessLocation>
     */
    public function selectableLocations(Business $business, int $actorUserId): Collection
    {
        $reachable = $this->guard->accessibleLocationIdsForBusiness($actorUserId, $business);

        if ($reachable === []) {
            return new Collection();
        }

        return BusinessLocation::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->whereIn('id', $reachable)
            ->orderBy('id')
            ->get(['id', 'uid', 'name']);
    }

    /**
     * Narrows a query over a Location-bearing table of ONE Business to the rows
     * $actorUserId may reach. The Business scope itself is the caller's.
     */
    public function restrict(EloquentBuilder|QueryBuilder $query, Business $business, int $actorUserId, string $column = 'location_id'): void
    {
        $reachable = $this->guard->accessibleLocationIdsForBusiness($actorUserId, $business);

        $query->where(function ($where) use ($column, $reachable): void {
            $where->whereNull($column);

            if ($reachable !== []) {
                $where->orWhereIn($column, $reachable);
            }
        });
    }
}
