<?php

namespace App\Library\Business\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Redesign — Slice 2B §4: attribute historical
 * conversations to a Business, only where the evidence proves which one.
 *
 * Immutable once its migration ships, exactly like BusinessDataTenancyBackfillV1
 * — a future correction is a V2, never an edit here.
 *
 * WHY THIS IS NOT LegacyBusinessResolver. That resolver's is_primary fallback
 * is a deliberately looser, general-purpose rule shared by eleven Pass-1
 * tables. A conversation is the first thing to be backfilled on the strength
 * of a phone number, and "this customer's primary Business" is exactly the
 * guess that would put one Business's thread in another Business's inbox. So
 * this class has its own, stricter rule, and the shared resolver is left
 * untouched.
 *
 * ORIENTATION. `chat_boxes.to` is ALWAYS the external counterparty and
 * `chat_boxes.from` is ALWAYS the Business-side number — for rows created by
 * an outbound send and by an inbound message alike. That was proven for every
 * producer in §4's audit, and there is no durable "created inbound" marker to
 * branch on even if one were wanted. So the evidence is read from `to`,
 * unconditionally.
 *
 * RESOLUTION ORDER
 *   1. Contact evidence — Contacts of the same customer whose phone equals
 *      the counterparty. Exactly one DISTINCT Business: resolved. Two or more
 *      distinct Businesses: a genuine conflict, left NULL, and step 2 is NOT
 *      consulted — a single-Business fallback must never overrule real
 *      evidence that the number belongs to several Businesses.
 *   2. Only when step 1 found NO evidence at all: a customer who owns exactly
 *      one Business resolves to it. Two or more: NULL. No primary, no lowest
 *      id, no first().
 *   3. Otherwise NULL.
 *
 * Idempotent: every query is scoped to `business_id IS NULL`, so a row that
 * already has a Business — backfilled or written live — is never revisited.
 * Never fails the migration. Logs aggregate counts only: this is the first
 * backfill whose evidence is a phone number, so no number, name or message
 * may appear in a log line.
 */
class ChatBoxBusinessBackfillV1
{
    private const CHUNK_SIZE = 500;

    /** @var array<int, array<int, int>> customerUserId => the Business ids that customer owns */
    private array $businessesByCustomer = [];

    /**
     * @return array{resolved: int, unresolved: int, ambiguous: int}
     */
    public function run(): array
    {
        $resolved = 0;
        $unresolved = 0;
        $ambiguous = 0;

        DB::table('chat_boxes')
            ->whereNull('business_id')
            ->orderBy('id')
            ->select(['id', 'user_id', 'to'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$resolved, &$unresolved, &$ambiguous): void {
                foreach ($rows as $row) {
                    [$businessId, $wasAmbiguous] = $this->resolve((int) $row->user_id, (string) $row->to);

                    if ($businessId === null) {
                        $unresolved++;

                        if ($wasAmbiguous) {
                            $ambiguous++;
                        }

                        continue;
                    }

                    // Re-checked NULL in the UPDATE itself, so a row a live
                    // producer attributed between the read and this write is
                    // never overwritten by the backfill.
                    $updated = DB::table('chat_boxes')
                        ->where('id', $row->id)
                        ->whereNull('business_id')
                        ->update(['business_id' => $businessId]);

                    $updated > 0 ? $resolved++ : $unresolved++;
                }
            });

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'ambiguous' => $ambiguous];
    }

    /**
     * Rows still NULL — expected for legacy data whose Business cannot be
     * proven, and reported rather than hidden.
     */
    public function unresolvedCount(): int
    {
        return DB::table('chat_boxes')->whereNull('business_id')->count();
    }

    /**
     * The counterparty in the exact form both producers already write, and
     * the form `contacts.phone` is stored in: digits only.
     *
     * Not a second normalizer. quickSend() and inboundDLR() each strip
     * `( ) + -` and spaces before writing `chat_boxes.to`, the Contacts model
     * strips the same set before storing `phone`, and the existing code
     * already compares the two with a plain `where('phone', $box->to)`. This
     * reproduces that one convention so the comparison matches what is
     * actually stored.
     */
    public static function normalizeCounterparty(string $number): string
    {
        return (string) preg_replace('/\D+/', '', $number);
    }

    /**
     * @return array{0: int|null, 1: bool} the Business id, and whether a NULL
     *                                     answer was caused by genuine
     *                                     cross-Business ambiguity
     */
    private function resolve(int $customerUserId, string $counterparty): array
    {
        $normalized = self::normalizeCounterparty($counterparty);

        // Step 1 — Contact evidence.
        if ($normalized !== '') {
            $evidence = DB::table('contacts')
                ->where('customer_id', $customerUserId)
                ->where('phone', $normalized)
                ->whereNotNull('business_id')
                ->distinct()
                ->pluck('business_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($evidence->count() === 1) {
                // Several Contact rows inside ONE Business are ordinary
                // same-tenant duplicates, not ambiguity.
                return [$evidence->first(), false];
            }

            if ($evidence->count() > 1) {
                // A real conflict. Step 2 is deliberately not consulted.
                return [null, true];
            }
        }

        // Step 2 — no Contact evidence at all.
        $owned = $this->businessesOwnedBy($customerUserId);

        if (count($owned) === 1) {
            return [$owned[0], false];
        }

        // Step 3.
        return [null, false];
    }

    /**
     * @return array<int, int>
     */
    private function businessesOwnedBy(int $customerUserId): array
    {
        // `businesses.customer_id` holds the owning USER id — Business::customer()
        // joins it to `customers.user_id` — which is the same identity
        // `chat_boxes.user_id` carries.
        return $this->businessesByCustomer[$customerUserId] ??= DB::table('businesses')
            ->where('customer_id', $customerUserId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
