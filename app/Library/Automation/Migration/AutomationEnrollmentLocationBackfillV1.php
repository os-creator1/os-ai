<?php

namespace App\Library\Automation\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Automations V1 completion — Location run-scope foundation, §7 of the
 * lane's own contract: attribute a historical `automation_enrollments` row
 * to a Location only where the evidence proves which one — mirroring
 * `ContactsLocationBackfillV1`'s exact philosophy (Contract 08B).
 *
 * THE EVIDENCE IS THE ENROLLMENT'S OWN CONTACT, and nothing else. An
 * enrollment's `contact_id` FK cascades from `contacts` (§4.5), so every
 * enrollment row's Contact still exists; if that Contact's own
 * `location_id` was itself proven (by `ContactsLocationBackfillV1` or a
 * live writer), the run's Location is exactly that — the run is that
 * Contact's journey, so its own Location is the correct, provable answer
 * for every trigger shape this backfill can see historically. A Contact
 * whose own Location was never proven leaves the enrollment NULL too —
 * never guessed, never defaulted to a Business's sole Location by this
 * class (a live enrollment created going forward is the one authority for
 * that; this backfill only copies an already-proven fact, it does not
 * re-derive one).
 *
 * Idempotent: every UPDATE re-checks `business_location_id IS NULL`, so a
 * row a concurrent process already set is never overwritten. Chunked so a
 * large table is never locked in one statement. Immutable once shipped — a
 * correction is a new V2 class, never an edit here.
 */
class AutomationEnrollmentLocationBackfillV1
{
    private const CHUNK_SIZE = 500;

    public function run(): int
    {
        $updated = 0;

        DB::table('automation_enrollments as e')
            ->join('contacts as c', function ($join): void {
                $join->on('c.id', '=', 'e.contact_id')->on('c.business_id', '=', 'e.business_id');
            })
            ->whereNull('e.business_location_id')
            ->whereNotNull('c.location_id')
            ->orderBy('e.id')
            ->select(['e.id as enrollment_id', 'c.location_id'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$updated): void {
                foreach ($rows as $row) {
                    $affected = DB::table('automation_enrollments')
                        ->where('id', $row->enrollment_id)
                        ->whereNull('business_location_id')
                        ->update(['business_location_id' => (int) $row->location_id]);

                    $updated += $affected;
                }
            }, 'e.id', 'enrollment_id');

        return $updated;
    }

    public function unresolvedCount(): int
    {
        return DB::table('automation_enrollments')->whereNull('business_location_id')->count();
    }
}
