<?php

namespace App\Library\Crm;

use App\Events\Crm\ContactTagAdded;
use App\Events\Crm\ContactTagRemoved;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\Business;
use App\Models\ContactTag;
use App\Models\Contacts;
use App\Models\Tag;
use App\Repositories\Contracts\ContactTagRepository;
use App\Repositories\Contracts\TagRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contact Tags foundation §3 — THE ONE canonical boundary for creating,
 * renaming, archiving, attaching and detaching tags. Controllers, and any
 * future Automation node, call this class — never
 * `TagRepository`/`ContactTagRepository` directly, and never a raw pivot
 * write — mirroring `CrmOpportunityService`'s own exact role in this
 * codebase: tenancy is RE-DERIVED here on every call, never assumed of the
 * caller, and every mutation is one transaction.
 *
 * CORRECTED (independent review, correction round 1). The first revision's
 * tenancy "re-derivation" read `$contact->business_id`/`$tag->business_id`
 * straight off the MODEL INSTANCE the caller handed in — which is exactly
 * as trustworthy as that caller's own code, not the database. `contact_tags`
 * carries no composite `(contact_id, business_id)` FK (only a plain
 * `contact_id` FK — see the migration's own docblock for why a composite FK
 * into the shared `contacts` table was judged disproportionate), so nothing
 * stopped a genuinely real Contact row belonging to Business B, loaded once,
 * then handed around with its in-memory `business_id` property mutated to
 * Business A, from reaching `attachTag()`/`detachTag()` and succeeding.
 *
 * Every method below that accepts an existing `Contact` or `Tag` now
 * re-reads that row FRESH, by primary key, from the database — inside the
 * mutation's own transaction, with a row lock where a concurrent mutation
 * of the SAME row could otherwise race this check — and compares THAT
 * freshly loaded row's `business_id` to the given Business. Every
 * subsequent repository call and event field uses the freshly loaded
 * AUTHORITATIVE model, never the caller's original instance: a caller
 * cannot smuggle a stale `location_id` or `name` into an event, because the
 * event is built from data this class itself just read.
 */
class TagManager
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ContactTagRepository $contactTags,
    ) {
    }

    /**
     * CORRECTED (correction round 1, §2) — the pre-write
     * `findByNormalizedName()` check is a courtesy for the ordinary case
     * (a fast, friendly `CrmRuleException` instead of a round-trip to the
     * database), never the actual safety guarantee: `tags_business_normalized
     * _unique` is. A concurrent create of the same normalized name that
     * wins the race is let through by that check and then refused by the
     * unique index itself — caught here and translated into the SAME
     * customer-safe exception, never a raw `UniqueConstraintViolationException`
     * reaching the caller.
     *
     * CORRECTED FURTHER (correction round 2) — `tags` also carries a `uid`
     * unique constraint (and any future one this class does not yet know
     * about). Catching `UniqueConstraintViolationException` broadly and
     * ALWAYS translating it to "a tag named X already exists" would
     * misreport a completely unrelated collision as a duplicate name. The
     * catch now re-queries `(business_id, normalized_name)` — the ONE fact
     * that actually proves a normalized-name race is what lost — and only
     * translates when that query confirms it; any other cause is rethrown
     * exactly as the database reported it.
     */
    public function createTag(Business $business, string $name): Tag
    {
        [$displayName, $normalized] = $this->normalize($name);

        if ($this->tags->findByNormalizedName($business, $normalized) !== null) {
            throw new CrmRuleException("A tag named \"{$displayName}\" already exists.");
        }

        try {
            return DB::transaction(fn () => $this->tags->create($business, $displayName, $normalized));
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->tags->findByNormalizedName($business, $normalized) !== null) {
                throw new CrmRuleException("A tag named \"{$displayName}\" already exists.");
            }

            // Some OTHER unique constraint lost the race (e.g. `uid`) —
            // never disguised as a duplicate name.
            throw $exception;
        }
    }

    public function renameTag(Business $business, Tag $tag, string $name): Tag
    {
        [$displayName, $normalized] = $this->normalize($name);

        return DB::transaction(function () use ($business, $tag, $displayName, $normalized): Tag {
            $authoritative = $this->authoritativeTag($business, $tag, lock: true);

            $existing = $this->tags->findByNormalizedName($business, $normalized);

            if ($existing !== null && (int) $existing->id !== (int) $authoritative->id) {
                throw new CrmRuleException("A tag named \"{$displayName}\" already exists.");
            }

            // Renaming never touches `contact_tags`: every existing membership
            // row references `tag_id`, not the name, so every Contact already
            // wearing this tag keeps it, under its new display name, with zero
            // additional writes.
            try {
                return $this->tags->rename($authoritative, $displayName, $normalized);
            } catch (UniqueConstraintViolationException $exception) {
                // Correction round 1, §2 — a concurrent create/rename to the
                // same normalized name won the race between the check above
                // and this write; the row lock narrows the window to almost
                // nothing, but "almost" is not "never", so the unique index
                // is still the real backstop.
                //
                // Correction round 2 — only translate when that is
                // PROVABLY what happened: re-query the normalized name and
                // require the collision to belong to some OTHER tag (never
                // this one). `uid` is also unique on `tags`; an unrelated
                // violation is rethrown exactly as reported, never
                // disguised as a duplicate name.
                $collision = $this->tags->findByNormalizedName($business, $normalized);

                if ($collision !== null && (int) $collision->id !== (int) $authoritative->id) {
                    throw new CrmRuleException("A tag named \"{$displayName}\" already exists.");
                }

                throw $exception;
            }
        });
    }

    public function archiveTag(Business $business, Tag $tag): Tag
    {
        return DB::transaction(function () use ($business, $tag): Tag {
            $authoritative = $this->authoritativeTag($business, $tag, lock: true);

            // Archiving never touches `contact_tags` — see the
            // `create_tags_table` migration's own docblock. Every existing
            // membership row is left exactly as it is.
            return $this->tags->archive($authoritative);
        });
    }

    /**
     * Idempotent: attaching an already-attached pair returns null and fires
     * no event — neither a second membership row nor a second
     * `ContactTagAdded` occurrence is ever created, including under a
     * genuine concurrent race (the repository's own unique-constraint catch
     * handles that, not a check here).
     */
    public function attachTag(Business $business, Contacts $contact, Tag $tag): ?ContactTag
    {
        return DB::transaction(function () use ($business, $contact, $tag): ?ContactTag {
            // Lock order fixed (Contact, then Tag) everywhere both are
            // locked in this class, so two concurrent attach/detach calls
            // naming the same pair in either argument order can never
            // deadlock against each other.
            $authoritativeContact = $this->authoritativeContact($business, $contact, lock: true);
            $authoritativeTag = $this->authoritativeTag($business, $tag, lock: true);

            if ($authoritativeTag->isArchived()) {
                throw new CrmRuleException("The tag \"{$authoritativeTag->name}\" is archived and cannot be attached to a new contact.");
            }

            $membership = $this->contactTags->attach($authoritativeContact, $authoritativeTag);

            if ($membership === null) {
                return null;
            }

            ContactTagAdded::dispatch(
                businessId: (int) $business->id,
                contactId: (int) $authoritativeContact->id,
                tagId: (int) $authoritativeTag->id,
                tagName: $authoritativeTag->name,
                locationId: $authoritativeContact->location_id !== null ? (int) $authoritativeContact->location_id : null,
                membershipId: (int) $membership->id,
            );

            return $membership;
        });
    }

    /**
     * Idempotent: detaching an already-absent pair returns false and fires
     * no event, including under a genuine concurrent race against another
     * detach of the same row.
     */
    public function detachTag(Business $business, Contacts $contact, Tag $tag): bool
    {
        return DB::transaction(function () use ($business, $contact, $tag): bool {
            $authoritativeContact = $this->authoritativeContact($business, $contact, lock: true);
            $authoritativeTag = $this->authoritativeTag($business, $tag, lock: true);

            $membershipId = $this->contactTags->detach($authoritativeContact, $authoritativeTag);

            if ($membershipId === null) {
                return false;
            }

            ContactTagRemoved::dispatch(
                businessId: (int) $business->id,
                contactId: (int) $authoritativeContact->id,
                tagId: (int) $authoritativeTag->id,
                tagName: $authoritativeTag->name,
                locationId: $authoritativeContact->location_id !== null ? (int) $authoritativeContact->location_id : null,
                membershipId: $membershipId,
            );

            return true;
        });
    }

    /** @return Collection<int, Tag> */
    public function tagsForBusiness(Business $business, bool $includeArchived = false): Collection
    {
        return $this->tags->listForBusiness($business, $includeArchived);
    }

    /** @return Collection<int, Tag> */
    public function tagsForContact(Business $business, Contacts $contact): Collection
    {
        // A read, not a mutation — no row lock is taken, but the Contact is
        // still re-read fresh rather than trusted from the caller's instance.
        $authoritative = $this->authoritativeContact($business, $contact);

        return $this->contactTags->tagsForContact($authoritative);
    }

    /** @return array{0: string, 1: string} [display name, normalized name] */
    private function normalize(string $name): array
    {
        $displayName = trim($name);

        if ($displayName === '') {
            throw new CrmRuleException('A tag needs a name.');
        }

        return [$displayName, mb_strtolower($displayName)];
    }

    /**
     * Re-reads the Contact fresh BY PRIMARY KEY and verifies the
     * AUTHORITATIVE row's own `business_id` — never the caller's possibly
     * stale or forged in-memory instance. `$lock` takes a row lock for the
     * duration of the enclosing transaction, for the mutation paths where a
     * concurrent change to this exact Contact must not interleave with this
     * decision.
     */
    private function authoritativeContact(Business $business, Contacts $contact, bool $lock = false): Contacts
    {
        $query = Contacts::query()->whereKey($contact->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        $authoritative = $query->first();

        if ($authoritative === null || $authoritative->business_id === null || (int) $authoritative->business_id !== (int) $business->id) {
            throw new CrmRuleException('This contact does not belong to this business.');
        }

        return $authoritative;
    }

    /**
     * The Tag-side sibling of `authoritativeContact()` — same re-read-by-
     * primary-key, same never-trust-the-instance discipline.
     */
    private function authoritativeTag(Business $business, Tag $tag, bool $lock = false): Tag
    {
        $query = Tag::query()->whereKey($tag->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        $authoritative = $query->first();

        if ($authoritative === null || (int) $authoritative->business_id !== (int) $business->id) {
            throw new CrmRuleException('This tag does not belong to this business.');
        }

        return $authoritative;
    }
}
