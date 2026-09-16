<?php

namespace App\Library\Crm\Migration;

use App\Enums\Business\BusinessLocationLifecycleState;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 08B §8: attribute historical CRM Opportunities
 * (`crm_opportunities` — the genuine Location-bound "Opportunity" record;
 * see this slice's implementation report for why the legacy `opportunities`
 * table is out of scope) to a Location, only where the evidence proves
 * which one — mirrors ChatBoxLocationBackfillV1's exact shape and
 * philosophy (Contract 06).
 *
 * THE EVIDENCE IS THE ROW'S OWN BUSINESS, and nothing else — never the
 * linked Contact's own Location, kept deliberately out of scope of this
 * slice to stay a direct structural mirror of the established pattern
 * rather than inventing a new inference rule.
 *
 *   - A Business with exactly one ACTIVE Location: that Location.
 *   - A Business with two or more active Locations: ambiguous, stays NULL.
 *   - A Business with no active Location at all: stays NULL.
 *
 * An ARCHIVED Location is never chosen.
 *
 * Idempotent: scoped to `location_id IS NULL`, and the UPDATE re-checks
 * NULL so a row a live writer attributed between the read and the write is
 * never overwritten. Never fails the migration. Logs aggregate counts
 * only: no deal title, contact identity or value in any log line.
 *
 * Immutable once shipped — a correction is a new V2 class, never an edit
 * here.
 */
class CrmOpportunityLocationBackfillV1
{
    private const CHUNK_SIZE = 500;

    /** @var array<int, int|null> business id => its single active Location id, or null */
    private array $locationByBusiness = [];

    /** @var array<int, bool> business id => whether it has two or more active Locations */
    private array $ambiguousBusiness = [];

    /**
     * @return array{resolved: int, unresolved: int, ambiguous: int}
     */
    public function run(): array
    {
        $resolved = 0;
        $unresolved = 0;
        $ambiguous = 0;

        DB::table('crm_opportunities')
            ->whereNull('location_id')
            ->orderBy('id')
            ->select(['id', 'business_id'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$resolved, &$unresolved, &$ambiguous): void {
                $this->cacheBusinesses($rows);

                foreach ($rows as $row) {
                    $businessId = $row->business_id === null ? null : (int) $row->business_id;
                    $locationId = $businessId === null ? null : ($this->locationByBusiness[$businessId] ?? null);

                    if ($locationId === null) {
                        $unresolved++;

                        if ($businessId !== null && ($this->ambiguousBusiness[$businessId] ?? false)) {
                            $ambiguous++;
                        }

                        continue;
                    }

                    $updated = DB::table('crm_opportunities')
                        ->where('id', $row->id)
                        ->whereNull('location_id')
                        ->update(['location_id' => $locationId]);

                    if ($updated > 0) {
                        $resolved++;
                    }
                }
            });

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'ambiguous' => $ambiguous];
    }

    public function unresolvedCount(): int
    {
        return DB::table('crm_opportunities')->whereNull('location_id')->count();
    }

    /**
     * @param  iterable<object>  $rows
     */
    private function cacheBusinesses(iterable $rows): void
    {
        $unknown = [];

        foreach ($rows as $row) {
            if ($row->business_id === null) {
                continue;
            }

            $businessId = (int) $row->business_id;

            if (! array_key_exists($businessId, $this->locationByBusiness)) {
                $unknown[$businessId] = true;
            }
        }

        if ($unknown === []) {
            return;
        }

        $byBusiness = [];

        DB::table('business_locations')
            ->whereIn('business_id', array_keys($unknown))
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->orderBy('business_id')
            ->orderBy('id')
            ->select(['id', 'business_id'])
            ->get()
            ->each(function ($location) use (&$byBusiness): void {
                $byBusiness[(int) $location->business_id][] = (int) $location->id;
            });

        foreach (array_keys($unknown) as $businessId) {
            $locations = $byBusiness[$businessId] ?? [];

            $this->locationByBusiness[$businessId] = count($locations) === 1 ? $locations[0] : null;
            $this->ambiguousBusiness[$businessId] = count($locations) > 1;
        }
    }
}
