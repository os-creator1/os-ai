<?php

namespace App\Library\Seo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contract 18 §11.4 — "at most N rows PER LOCATION" in ONE query.
 *
 * A single `limit N` over several Locations lets one busy Location fill the
 * whole page and starve the others. This runs one ordered, limited branch per
 * Location and joins them with UNION ALL, so every Location keeps its own most
 * recent N rows and the query count stays constant however many Locations
 * there are. Read-only; the caller has already filtered the ids to the
 * Locations the actor may access.
 */
final class SeoPerLocationLimit
{
    /**
     * @param  array<int, int>  $locationIds  already-authorized Location ids
     * @param  callable(int): Builder  $branch  the ordered query for ONE Location, WITHOUT a limit
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public static function rows(array $locationIds, int $limit, callable $branch): Collection
    {
        $query = null;

        foreach (array_values($locationIds) as $locationId) {
            $one = $branch((int) $locationId)->limit($limit);

            if ($query === null) {
                $query = $one;

                continue;
            }

            $query->unionAll($one->toBase());
        }

        return $query === null ? new Collection() : $query->get();
    }
}
