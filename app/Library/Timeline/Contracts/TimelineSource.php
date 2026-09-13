<?php

namespace App\Library\Timeline\Contracts;

use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;

/**
 * One kind of canonical row that can say something happened with a person.
 *
 * THE EXTENSION POINT. Email, forms, invoices, payments, bookings and CRM
 * opportunities each join the timeline by implementing this and being tagged
 * ContactActivityTimeline::SOURCES_TAG — the Conversations screen does not
 * change.
 *
 * A source MUST:
 *   - read only rows of $subject->business (every query `business_id = ?`);
 *   - read persisted rows only — no inferred event, nothing "AI did" unless a
 *     row proves it;
 *   - run a fixed number of queries, whatever the row count (no per-row reads);
 *   - return at most $limit items, newest first;
 *   - return nothing rather than guess when its rows cannot be tied to this
 *     person (a contact-keyed source with no single Contact, say).
 */
interface TimelineSource
{
    /**
     * @return list<TimelineItem> newest first
     */
    public function recent(TimelineSubject $subject, int $limit): array;
}
