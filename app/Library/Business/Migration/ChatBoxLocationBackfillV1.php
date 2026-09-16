<?php

namespace App\Library\Business\Migration;

use App\Enums\Business\BusinessLocationLifecycleState;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 06 §8: attribute historical conversations to a
 * Location, only where the evidence proves which one.
 *
 * Immutable once its migration ships, exactly like ChatBoxBusinessBackfillV1 —
 * a future correction is a V2, never an edit here.
 *
 * THE EVIDENCE IS THE ROW'S OWN BUSINESS, and nothing else. A conversation
 * already attributed to a Business that has exactly one ACTIVE Location can
 * only have happened at that Location, so it resolves. Everything else stays
 * NULL:
 *
 *   - `business_id IS NULL` — including every legacy Agency AI-Prospecting row
 *     (contract §3's site 4), which is written with no Business at all. With no
 *     Business there is no evidence, and the customer's other Businesses are
 *     not evidence about THIS conversation. Counted as unresolved, never
 *     guessed.
 *   - A Business with two or more active Locations — genuine ambiguity, and
 *     `business_messaging_numbers` carries no Location to narrow it (§3).
 *     Counted as ambiguous as well as unresolved, so the migration can report
 *     how much of the remainder is a real conflict rather than missing data.
 *   - A Business with no active Location at all — nothing to attribute to.
 *
 * An ARCHIVED Location is never chosen: archiving is how this product retires
 * a Location, and a conversation is not attributed to a place that is no
 * longer in use. This matches BusinessLocationLifecycleState's own meaning and
 * the capacity rules that count active Locations only.
 *
 * Idempotent: every query is scoped to `location_id IS NULL`, so a row that
 * already has a Location — backfilled or written live — is never revisited,
 * and the UPDATE re-checks NULL so a row a live producer attributed between
 * the read and the write is never overwritten (and is counted as neither
 * resolved nor unresolved). Never fails the migration.
 * Logs aggregate counts only: no phone number, name or message content may
 * appear in a log line, matching ChatBoxBusinessBackfillV1's own discipline.
 *
 * The single-active-Location rule is stated here in its own set-based form
 * rather than calling ChatBox::singleActiveLocationIdFor(): a shipped
 * backfill's behaviour is frozen at the version that ran, and this resolves
 * one Business per distinct id per chunk instead of once per row.
 */
class ChatBoxLocationBackfillV1
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

        DB::table('chat_boxes')
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

                    // Re-checked NULL in the UPDATE itself, so a row a live
                    // producer attributed between the read and this write is
                    // never overwritten by the backfill. Such a row is
                    // counted as neither: it is no longer unresolved, and
                    // this backfill did not resolve it.
                    $updated = DB::table('chat_boxes')
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

    /**
     * Rows still NULL — expected for legacy data whose Location cannot be
     * proven, and reported rather than hidden.
     */
    public function unresolvedCount(): int
    {
        return DB::table('chat_boxes')->whereNull('location_id')->count();
    }

    /**
     * One read per chunk for every Business this chunk mentions and has not
     * been seen before: the active Locations of each, capped at two per
     * Business because "exactly one" is all this needs to know.
     *
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
