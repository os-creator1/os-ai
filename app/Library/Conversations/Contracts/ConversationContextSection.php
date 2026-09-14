<?php

namespace App\Library\Conversations\Contracts;

use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;

/**
 * One extra block in the conversation's contact panel, supplied by a domain
 * that has something real to say about this person.
 *
 * THE SEAM FOR WHAT DOES NOT EXIST YET. A CRM opportunity summary, open
 * invoices or upcoming bookings join the panel by implementing this and being
 * tagged ConversationContextReader::SECTIONS_TAG. Nothing is registered today,
 * so the panel shows no placeholder for any of them.
 *
 * An implementation reads only $business's rows and returns null when it has
 * nothing to show — never an empty or invented block.
 */
interface ConversationContextSection
{
    /**
     * @return array{title: string, rows: list<array{label: string, value: string}>}|null
     */
    public function describe(Business $business, ChatBox $conversation, ?Contacts $contact): ?array;
}
