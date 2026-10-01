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
 */
class TagManager
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ContactTagRepository $contactTags,
    ) {
    }

    public function createTag(Business $business, string $name): Tag
    {
        [$displayName, $normalized] = $this->normalize($name);

        if ($this->tags->findByNormalizedName($business, $normalized) !== null) {
            throw new CrmRuleException("A tag named \"{$displayName}\" already exists.");
        }

        return DB::transaction(fn () => $this->tags->create($business, $displayName, $normalized));
    }

    public function renameTag(Business $business, Tag $tag, string $name): Tag
    {
        $this->assertTagBelongsToBusiness($business, $tag);
        [$displayName, $normalized] = $this->normalize($name);

        $existing = $this->tags->findByNormalizedName($business, $normalized);

        if ($existing !== null && (int) $existing->id !== (int) $tag->id) {
            throw new CrmRuleException("A tag named \"{$displayName}\" already exists.");
        }

        // Renaming never touches `contact_tags`: every existing membership
        // row references `tag_id`, not the name, so every Contact already
        // wearing this tag keeps it, under its new display name, with zero
        // additional writes.
        return DB::transaction(fn () => $this->tags->rename($tag, $displayName, $normalized));
    }

    public function archiveTag(Business $business, Tag $tag): Tag
    {
        $this->assertTagBelongsToBusiness($business, $tag);

        // Archiving never touches `contact_tags` either — see the
        // `create_tags_table` migration's own docblock. Every existing
        // membership row is left exactly as it is.
        return DB::transaction(fn () => $this->tags->archive($tag));
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
        $this->assertContactBelongsToBusiness($business, $contact);
        $this->assertTagBelongsToBusiness($business, $tag);

        if ($tag->isArchived()) {
            throw new CrmRuleException("The tag \"{$tag->name}\" is archived and cannot be attached to a new contact.");
        }

        return DB::transaction(function () use ($business, $contact, $tag): ?ContactTag {
            $membership = $this->contactTags->attach($contact, $tag);

            if ($membership === null) {
                return null;
            }

            ContactTagAdded::dispatch(
                businessId: (int) $business->id,
                contactId: (int) $contact->id,
                tagId: (int) $tag->id,
                tagName: $tag->name,
                locationId: $contact->location_id !== null ? (int) $contact->location_id : null,
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
        $this->assertContactBelongsToBusiness($business, $contact);
        $this->assertTagBelongsToBusiness($business, $tag);

        return DB::transaction(function () use ($business, $contact, $tag): bool {
            $membershipId = $this->contactTags->detach($contact, $tag);

            if ($membershipId === null) {
                return false;
            }

            ContactTagRemoved::dispatch(
                businessId: (int) $business->id,
                contactId: (int) $contact->id,
                tagId: (int) $tag->id,
                tagName: $tag->name,
                locationId: $contact->location_id !== null ? (int) $contact->location_id : null,
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
        $this->assertContactBelongsToBusiness($business, $contact);

        return $this->contactTags->tagsForContact($contact);
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

    private function assertContactBelongsToBusiness(Business $business, Contacts $contact): void
    {
        if ($contact->business_id === null || (int) $contact->business_id !== (int) $business->id) {
            throw new CrmRuleException('This contact does not belong to this business.');
        }
    }

    private function assertTagBelongsToBusiness(Business $business, Tag $tag): void
    {
        if ((int) $tag->business_id !== (int) $business->id) {
            throw new CrmRuleException('This tag does not belong to this business.');
        }
    }
}
