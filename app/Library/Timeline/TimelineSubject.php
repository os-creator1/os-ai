<?php

namespace App\Library\Timeline;

use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;

/**
 * The person a timeline is about, inside ONE Business.
 *
 * A person is known here by two things, and a source uses whichever its rows
 * carry:
 *
 *   - the external number, normalized to digits — for rows keyed by number
 *     (conversation messages, sent reports, the block list);
 *   - the Contact, only when exactly one Contact of this Business has that
 *     number (ChatBox::resolveDisplayContact()) — for rows keyed by contact
 *     (automation enrollments and executions, the contact record itself).
 *
 * With no Contact, or several on the same number, contact-keyed sources add
 * nothing: attributing one contact's automation history to a number two
 * contacts share would be a guess.
 */
final class TimelineSubject
{
    public readonly string $number;

    public function __construct(
        public readonly Business $business,
        string $counterpartNumber,
        public readonly ?ChatBox $conversation = null,
        public readonly ?Contacts $contact = null,
    ) {
        $this->number = ChatBoxBusinessBackfillV1::normalizeCounterparty($counterpartNumber);
    }

    public static function forConversation(Business $business, ChatBox $conversation, ?Contacts $contact): self
    {
        return new self($business, (string) $conversation->to, $conversation, $contact);
    }

    /**
     * The spellings a stored number may have: bare digits, or with a leading
     * plus. The same two ContactDirectory matches conversations with.
     *
     * @return list<string>
     */
    public function numberVariants(): array
    {
        return $this->number === '' ? [] : [$this->number, '+' . $this->number];
    }
}
