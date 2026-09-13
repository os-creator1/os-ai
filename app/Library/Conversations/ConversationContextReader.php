<?php

namespace App\Library\Conversations;

use App\Library\Contacts\ContactDirectory;
use App\Library\Conversations\Contracts\ConversationContextSection;
use App\Library\Timeline\TimelineSubject;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Builds the contact panel beside an open conversation.
 *
 * The person's identity comes from ContactDirectory::profile() — the same
 * person-first read the People page shows, not a second copy of it. Messaging
 * status is the Contact's subscription, overridden by a block-list entry for
 * this number in this Business, which is what actually stops texts.
 */
final class ConversationContextReader
{
    public const SECTIONS_TAG = 'conversation_context.sections';

    /** @var list<ConversationContextSection> */
    private readonly array $sections;

    /**
     * @param  iterable<ConversationContextSection>  $sections
     */
    public function __construct(private readonly ContactDirectory $directory, iterable $sections)
    {
        $this->sections = array_values(is_array($sections) ? $sections : iterator_to_array($sections, false));
    }

    public function read(Business $business, ChatBox $conversation, ?Contacts $contact): ConversationContext
    {
        $variants = TimelineSubject::forConversation($business, $conversation, $contact)->numberVariants();

        $blocked = $variants !== [] && DB::table('blacklists')
            ->where('business_id', $business->id)
            ->whereIn('number', $variants)
            ->exists();

        $sections = [];

        foreach ($this->sections as $section) {
            $described = $section->describe($business, $conversation, $contact);

            if ($described !== null && trim((string) ($described['title'] ?? '')) !== '' && ($described['rows'] ?? []) !== []) {
                $sections[] = $described;
            }
        }

        if ($contact === null || (int) $contact->business_id !== (int) $business->id) {
            return new ConversationContext(
                phone: (string) $conversation->to,
                businessNumber: $conversation->from !== null ? (string) $conversation->from : null,
                consent: $blocked ? ConversationContext::CONSENT_BLOCKED : ConversationContext::CONSENT_NO_CONTACT,
                sections: $sections,
            );
        }

        $profile = $this->directory->profile($business, $contact, false);

        return new ConversationContext(
            phone: (string) $profile['phone'],
            businessNumber: $conversation->from !== null ? (string) $conversation->from : null,
            consent: match (true) {
                $blocked => ConversationContext::CONSENT_BLOCKED,
                $profile['subscribed'] => ConversationContext::CONSENT_SUBSCRIBED,
                default => ConversationContext::CONSENT_UNSUBSCRIBED,
            },
            contactUid: (string) $contact->uid,
            name: $profile['name'],
            email: $profile['email'],
            company: $profile['company'],
            group: $profile['group']['name'] ?? null,
            addedAt: $profile['added'],
            details: $profile['details'],
            sections: $sections,
        );
    }
}
