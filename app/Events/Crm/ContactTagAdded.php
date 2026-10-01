<?php

namespace App\Events\Crm;

/**
 * Contact Tags foundation §4 — a Contact was tagged.
 *
 * Dispatched by `TagManager::attachTag()` exactly once per genuinely NEW
 * attachment: a duplicate attach (already-attached pair, including a
 * concurrent duplicate caught by `contact_tags`' own unique constraint)
 * dispatches nothing. See `ContactTagEvent` for the shared field/occurrence
 * contract.
 */
class ContactTagAdded extends ContactTagEvent
{
    public function occurrenceKey(): string
    {
        return 'contact_tag_added:' . $this->membershipId;
    }
}
