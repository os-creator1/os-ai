<?php

namespace App\Library\Crm\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact Tags foundation §5 — migrate the legacy `contacts.tags` JSON blob
 * into the canonical `tags` / `contact_tags` tables, once, deterministically,
 * and without guessing at anything this class cannot prove.
 *
 * THE SCHEMA AMBIGUITY, REPORTED RATHER THAN GUESSED AT. `contacts.tags` is
 * not represented by any tracked migration anywhere in this repository's
 * history (confirmed: `grep -r tags database/migrations` finds only the
 * unrelated `template_tags` table). Verified directly against a fresh,
 * fully-migrated disposable database built from this branch's own migration
 * history: the column DOES NOT EXIST there at all. Wherever it does exist
 * (an inherited, out-of-band CodeCanyon SQL dump column, never a Laravel
 * migration), this class cannot assume its presence, and a backfill that
 * unconditionally `SELECT`s it would throw "Unknown column" on every
 * disposable/CI database this project's own rules require testing against.
 * `Schema::hasColumn()` is therefore checked FIRST, and its absence is a
 * clean, reported no-op — never an inferred "there is nothing to migrate"
 * treated as equivalent to "the column never existed."
 *
 * THE REAL SHAPE, PER THE LIVE CODE (`Contacts::getTags()`/`addTags()`/
 * `updateTags()`): a JSON array of plain tag-name strings, e.g.
 * `["vip","lead-2025"]`. No `{id,name}` object shape, no comma-separated
 * storage at rest, confirmed by every writer AND by `Segments`' own
 * `LIKE '%"<tag>"%'` substring match (which only makes sense against bare
 * quoted strings).
 *
 * DETERMINISTIC NORMALIZATION. Contacts are processed in ascending `id`
 * order; for each distinct normalized name (`mb_strtolower(trim($name))`)
 * within a Business, the FIRST contact to present it wins the canonical
 * display `name` — every later contact reuses that same `tags` row via
 * `(business_id, normalized_name)`, never creating a second, differently-
 * cased row for what is the same tag.
 *
 * REPORTED, NEVER GUESSED, FAILURE MODES — each counted and returned, never
 * silently dropped or silently assumed clean:
 *   - a contact with no usable Business (`business_id` NULL) cannot have a
 *     Business-scoped tag created on its behalf — counted as
 *     `unmappable_contacts`;
 *   - a `tags` value that is non-empty but fails `json_decode` is counted
 *     as `malformed_contacts`, the contact is skipped, nothing is guessed;
 *   - a decoded value that is not an array at all is treated the same way;
 *   - an individual array ENTRY that is not a non-empty string is counted
 *     in `malformed_entries` and skipped on its own — the rest of that same
 *     contact's otherwise-valid tag entries are still migrated, because
 *     discarding a Contact's entire tag history over one bad entry would be
 *     its own kind of data loss.
 *
 * IDEMPOTENT AND RESUMABLE, by construction, never by a separate "already
 * ran" flag: tag rows are found-or-created by `(business_id,
 * normalized_name)`, and every `contact_tags` insert is guarded by
 * `whereNotExists` so re-running this class after a partial run (or after
 * the live `TagManager` has already attached some of the same tags through
 * ordinary product use) changes nothing it already changed.
 *
 * NO DOMAIN EVENTS. This is historical data taking its canonical shape for
 * the first time, not a new occurrence — `ContactTagAdded` is for the live
 * `TagManager` only. Dispatching it here would fabricate event history that
 * never happened and could one day be misread by an Automation trigger as
 * "just tagged."
 */
class LegacyContactTagsBackfillV1
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{
     *     ran: bool,
     *     reason: ?string,
     *     tags_created: int,
     *     memberships_created: int,
     *     contacts_processed: int,
     *     unmappable_contacts: int,
     *     malformed_contacts: int,
     *     malformed_entries: int,
     * }
     */
    public function run(): array
    {
        if (! Schema::hasColumn('contacts', 'tags')) {
            return $this->result(ran: false, reason: 'contacts.tags column does not exist on this database');
        }

        $tagsCreated = 0;
        $membershipsCreated = 0;
        $contactsProcessed = 0;
        $unmappableContacts = 0;
        $malformedContacts = 0;
        $malformedEntries = 0;

        // Per-Business, per-normalized-name cache of the resolved tag id,
        // so the SAME tag across many contacts in one Business is resolved
        // (and, on first sight, created) exactly once per run — not once
        // per contact.
        $resolvedTagIds = [];

        DB::table('contacts')
            ->whereNotNull('tags')
            ->where('tags', '!=', '')
            ->orderBy('id')
            ->select(['id', 'business_id', 'tags'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (
                &$tagsCreated, &$membershipsCreated, &$contactsProcessed,
                &$unmappableContacts, &$malformedContacts, &$malformedEntries, &$resolvedTagIds,
            ): void {
                foreach ($rows as $row) {
                    $contactsProcessed++;

                    if ($row->business_id === null) {
                        $unmappableContacts++;

                        continue;
                    }

                    $decoded = json_decode((string) $row->tags, true);

                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                        $malformedContacts++;

                        continue;
                    }

                    $businessId = (int) $row->business_id;

                    foreach ($decoded as $entry) {
                        if (! is_string($entry) || trim($entry) === '') {
                            $malformedEntries++;

                            continue;
                        }

                        $displayName = trim($entry);
                        $normalized = mb_strtolower($displayName);
                        $cacheKey = $businessId . ':' . $normalized;

                        if (! isset($resolvedTagIds[$cacheKey])) {
                            $tagId = DB::table('tags')
                                ->where('business_id', $businessId)
                                ->where('normalized_name', $normalized)
                                ->value('id');

                            if ($tagId === null) {
                                $tagId = DB::table('tags')->insertGetId([
                                    'uid' => (string) \Illuminate\Support\Str::uuid(),
                                    'business_id' => $businessId,
                                    'name' => $displayName,
                                    'normalized_name' => $normalized,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $tagsCreated++;
                            }

                            $resolvedTagIds[$cacheKey] = (int) $tagId;
                        }

                        $tagId = $resolvedTagIds[$cacheKey];

                        $alreadyAttached = DB::table('contact_tags')
                            ->where('contact_id', $row->id)
                            ->where('tag_id', $tagId)
                            ->exists();

                        if ($alreadyAttached) {
                            continue;
                        }

                        DB::table('contact_tags')->insert([
                            'contact_id' => $row->id,
                            'tag_id' => $tagId,
                            'business_id' => $businessId,
                            'created_at' => now(),
                        ]);
                        $membershipsCreated++;
                    }
                }
            }, 'id');

        return $this->result(
            ran: true,
            reason: null,
            tagsCreated: $tagsCreated,
            membershipsCreated: $membershipsCreated,
            contactsProcessed: $contactsProcessed,
            unmappableContacts: $unmappableContacts,
            malformedContacts: $malformedContacts,
            malformedEntries: $malformedEntries,
        );
    }

    private function result(
        bool $ran,
        ?string $reason,
        int $tagsCreated = 0,
        int $membershipsCreated = 0,
        int $contactsProcessed = 0,
        int $unmappableContacts = 0,
        int $malformedContacts = 0,
        int $malformedEntries = 0,
    ): array {
        return [
            'ran' => $ran,
            'reason' => $reason,
            'tags_created' => $tagsCreated,
            'memberships_created' => $membershipsCreated,
            'contacts_processed' => $contactsProcessed,
            'unmappable_contacts' => $unmappableContacts,
            'malformed_contacts' => $malformedContacts,
            'malformed_entries' => $malformedEntries,
        ];
    }
}
