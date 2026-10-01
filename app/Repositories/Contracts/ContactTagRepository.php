<?php

namespace App\Repositories\Contracts;

use App\Models\ContactTag;
use App\Models\Contacts;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * Contact Tags foundation §2/§3 — pure persistence for the `contact_tags`
 * membership. Idempotency and concurrency safety are the DB's own
 * `UNIQUE(contact_id, tag_id)` constraint, never a check-then-act race here
 * (`attach()`'s own contract: a duplicate is a `null` return, not an
 * exception reaching the caller) — `TagManager` is what decides whether a
 * `null` return means "do nothing" or "fire no event."
 */
interface ContactTagRepository extends BaseRepository
{
    /** Null when the pair was already attached — never an exception for that case. */
    public function attach(Contacts $contact, Tag $tag): ?ContactTag;

    /** The removed row's own former id, or null when nothing was attached to remove. */
    public function detach(Contacts $contact, Tag $tag): ?int;

    public function isAttached(Contacts $contact, Tag $tag): bool;

    /** @return Collection<int, Tag> */
    public function tagsForContact(Contacts $contact): Collection;

    /** @return Collection<int, Contacts> */
    public function contactsForTag(Tag $tag): Collection;
}
