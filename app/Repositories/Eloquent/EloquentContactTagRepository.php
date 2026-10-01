<?php

namespace App\Repositories\Eloquent;

use App\Models\ContactTag;
use App\Models\Contacts;
use App\Models\Tag;
use App\Repositories\Contracts\ContactTagRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

class EloquentContactTagRepository extends EloquentBaseRepository implements ContactTagRepository
{
    public function __construct(ContactTag $contactTag)
    {
        parent::__construct($contactTag);
    }

    /**
     * Insert-and-catch, never select-then-insert: the same race-safe shape
     * `WorkflowEnrollmentService::enroll()` already uses against
     * `automation_enrollments.enrollment_key`. A concurrent duplicate
     * attempt loses the `contact_tags_contact_tag_unique` race and is
     * handed back here as a caught exception, never as a 500 the caller
     * has to guard against separately.
     */
    public function attach(Contacts $contact, Tag $tag): ?ContactTag
    {
        try {
            return ContactTag::query()->create([
                'contact_id' => $contact->id,
                'tag_id' => $tag->id,
                'business_id' => $tag->business_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function detach(Contacts $contact, Tag $tag): ?int
    {
        $existing = $this->query()
            ->where('contact_id', $contact->id)
            ->where('tag_id', $tag->id)
            ->first();

        if ($existing === null) {
            return null;
        }

        $membershipId = (int) $existing->id;

        $deleted = $this->query()->whereKey($existing->id)->delete();

        return $deleted > 0 ? $membershipId : null;
    }

    public function isAttached(Contacts $contact, Tag $tag): bool
    {
        return $this->query()
            ->where('contact_id', $contact->id)
            ->where('tag_id', $tag->id)
            ->exists();
    }

    /**
     * EVERY tag this Contact wears, archived or not. Hiding an archived
     * tag here would make a genuinely intact membership look like data that
     * silently vanished — archiving only refuses NEW attachment
     * (`TagManager::attachTag()`'s own check), never an existing Contact's
     * view of what it is already tagged with.
     */
    public function tagsForContact(Contacts $contact): Collection
    {
        return Tag::query()
            ->join('contact_tags', 'contact_tags.tag_id', '=', 'tags.id')
            ->where('contact_tags.contact_id', $contact->id)
            ->orderBy('tags.name')
            ->select('tags.*')
            ->get();
    }

    public function contactsForTag(Tag $tag): Collection
    {
        return Contacts::query()
            ->join('contact_tags', 'contact_tags.contact_id', '=', 'contacts.id')
            ->where('contact_tags.tag_id', $tag->id)
            ->select('contacts.*')
            ->get();
    }
}
