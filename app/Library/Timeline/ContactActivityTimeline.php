<?php

namespace App\Library\Timeline;

use App\Library\Timeline\Contracts\TimelineSource;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;

/**
 * One chronological timeline for one person in one Business, merged from every
 * registered TimelineSource.
 *
 * A READ MODEL, NOT A LEDGER. Each source runs its own bounded, Business-scoped
 * query on the table that already owns its facts; the results merge here in
 * PHP. There is no activity table and nothing is written — the same shape as
 * the Business Home's Recent work (RecentWorkReader), for the same reason: a
 * projection would be a second copy of facts that already have an owner, and
 * would need its own contract.
 *
 * MERGE RULES, in order:
 *   1. Each source returns at most PER_SOURCE_LIMIT items. One that had more is
 *      cut at its oldest kept item, and every other source is cut at that same
 *      moment, so the window shown is complete for all of them.
 *   2. An item another item `represents` is dropped (a message stamped with the
 *      automation step that sent it stands for that step's card).
 *   3. Oldest first; ties by source registration order, then by row id.
 */
final class ContactActivityTimeline
{
    public const SOURCES_TAG = 'contact_activity_timeline.sources';

    public const PER_SOURCE_LIMIT = 200;

    /** @var list<TimelineSource> */
    private readonly array $sources;

    /**
     * @param  iterable<TimelineSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        $this->sources = array_values(is_array($sources) ? $sources : iterator_to_array($sources, false));
    }

    public function forConversation(Business $business, ChatBox $conversation, ?Contacts $contact): TimelinePage
    {
        return $this->forSubject(TimelineSubject::forConversation($business, $conversation, $contact));
    }

    public function forSubject(TimelineSubject $subject): TimelinePage
    {
        $collected = [];
        $cutOff = null;

        foreach ($this->sources as $sourceIndex => $source) {
            $items = array_values($source->recent($subject, self::PER_SOURCE_LIMIT + 1));

            if (count($items) > self::PER_SOURCE_LIMIT) {
                $items = array_slice($items, 0, self::PER_SOURCE_LIMIT);
                $oldestKept = end($items)->at;

                if ($cutOff === null || $oldestKept->greaterThan($cutOff)) {
                    $cutOff = $oldestKept;
                }
            }

            foreach ($items as $item) {
                $collected[] = [$sourceIndex, $item];
            }
        }

        $represented = [];

        foreach ($collected as [, $item]) {
            foreach ($item->represents as $key) {
                $represented[$key] = true;
            }
        }

        $kept = array_values(array_filter(
            $collected,
            static fn (array $entry): bool => ! isset($represented[$entry[1]->key])
                && ($cutOff === null || $entry[1]->at->greaterThanOrEqualTo($cutOff)),
        ));

        usort($kept, static function (array $a, array $b): int {
            return [$a[1]->at->getTimestamp(), $a[0], $a[1]->sequence]
                <=> [$b[1]->at->getTimestamp(), $b[0], $b[1]->sequence];
        });

        return new TimelinePage(
            $subject,
            array_map(static fn (array $entry): TimelineItem => $entry[1], $kept),
            $cutOff !== null,
        );
    }
}
