<?php

namespace App\Events\Crm;

/**
 * Contact Tags foundation §4 — a tag was removed from a Contact.
 *
 * Dispatched by `TagManager::detachTag()` exactly once per genuinely
 * removed row: a duplicate detach (already-absent pair, including a
 * concurrent duplicate racing the same `contact_tags` row) dispatches
 * nothing. `membershipId` is the id the deleted `contact_tags` row carried
 * an instant before its own deletion, captured by `TagManager` inside the
 * same transaction — AUTO_INCREMENT ids are never reused, so this stays a
 * stable, unique occurrence identity even though the row it names no
 * longer exists to be queried back. See `ContactTagEvent` for the shared
 * field/occurrence contract.
 */
class ContactTagRemoved extends ContactTagEvent
{
    public function occurrenceKey(): string
    {
        return 'contact_tag_removed:' . $this->membershipId;
    }
}
