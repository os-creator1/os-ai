<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The person's standing with the Business, where a row records when it changed.
 *
 *   `contacts.created_at`   "Added to contacts" — only for the one Contact the
 *                           conversation resolves to;
 *   `blacklists`            an opt-out (an inbound STOP or opt-out keyword
 *                           writes "Optout by User") or a block from the inbox
 *                           ("Blacklisted by …"), keyed by number and scoped to
 *                           this Business — so it shows even when the STOP
 *                           deleted the conversation it arrived on.
 *
 * A subscription status changed without a block-list row leaves no dated
 * record, so it is not an event here; the contact panel shows the current
 * status instead.
 */
final class ContactRecordSource implements TimelineSource
{
    public const OPT_OUT_REASON = 'Optout by User';

    public function recent(TimelineSubject $subject, int $limit): array
    {
        $items = $this->blockList($subject, $limit);

        $contact = $subject->contact;

        if ($contact !== null && (int) $contact->business_id === (int) $subject->business->id && $contact->created_at !== null) {
            $group = $contact->contactGroup;

            $items[] = new TimelineItem(
                key: 'contact_added:' . $contact->id,
                kind: TimelineItemKind::Activity,
                at: CarbonImmutable::instance($contact->created_at),
                title: 'Added to contacts',
                detail: $group !== null && (int) $group->business_id === (int) $subject->business->id ? 'Group: ' . $group->name : null,
                icon: 'user-plus',
                sequence: (int) $contact->id,
            );
        }

        usort($items, static fn (TimelineItem $a, TimelineItem $b): int => [$b->at->getTimestamp(), $b->sequence] <=> [$a->at->getTimestamp(), $a->sequence]);

        return array_slice($items, 0, $limit);
    }

    /** @return list<TimelineItem> */
    private function blockList(TimelineSubject $subject, int $limit): array
    {
        $variants = $subject->numberVariants();

        if ($variants === []) {
            return [];
        }

        return DB::table('blacklists')
            ->where('business_id', $subject->business->id)
            ->whereIn('number', $variants)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'reason', 'created_at'])
            ->map(function (object $row): TimelineItem {
                $reason = trim((string) $row->reason);

                $title = match (true) {
                    $reason === self::OPT_OUT_REASON => 'Opted out of texts',
                    str_starts_with($reason, 'Blacklisted by') => 'Blocked from Conversations',
                    default => 'Added to the block list',
                };

                return new TimelineItem(
                    key: 'block_list:' . $row->id,
                    kind: TimelineItemKind::Activity,
                    at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
                    title: $title,
                    tone: TimelineTone::Warning,
                    icon: 'ban',
                    sequence: (int) $row->id,
                );
            })
            ->all();
    }
}
