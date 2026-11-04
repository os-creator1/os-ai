<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineDirection;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Historical email, calls, notes and CRM activity brought over from another
 * system (`imported_timeline_items`). Read-only: an item here is never
 * retryable, carries no send state, and nothing in this class can transmit.
 * Scoped to the Business AND the conversation, so another tenant's history can
 * never surface here.
 */
final class ImportedHistorySource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        $conversation = $subject->conversation;

        if ($conversation === null || (int) $conversation->business_id !== (int) $subject->business->id) {
            return [];
        }

        return DB::table('imported_timeline_items')
            ->where('business_id', $subject->business->id)
            ->where('chat_box_id', $conversation->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): TimelineItem => $this->item($row))
            ->all();
    }

    private function item(object $row): TimelineItem
    {
        $at = CarbonImmutable::parse((string) $row->occurred_at, config('app.timezone'));
        $key = 'imported:' . $row->source_system . ':' . $row->source_id;
        $direction = $row->direction === 'inbound' ? TimelineDirection::Inbound : TimelineDirection::Outbound;
        $origin = $row->source_system === 'gohighlevel' ? 'GoHighLevel' : (string) $row->source_system;
        $failed = in_array($row->source_status, ['failed', 'undelivered'], true);

        if (in_array($row->kind, ['email', 'sms'], true)) {
            return new TimelineItem(
                key: $key,
                kind: TimelineItemKind::Message,
                at: $at,
                body: trim(($row->title !== '' ? $row->title . "\n\n" : '') . (string) $row->body) ?: null,
                direction: $direction,
                channel: $row->kind,
                via: 'Imported from ' . $origin . ($row->kind === 'sms' ? ' · SMS' : ' · Email'),
                detail: $row->source_status !== null ? 'Originally: ' . $row->source_status : null,
                tone: $failed ? TimelineTone::Warning : TimelineTone::Neutral,
                sequence: (int) $row->id,
            );
        }

        return new TimelineItem(
            key: $key,
            kind: TimelineItemKind::Activity,
            at: $at,
            title: $row->title,
            detail: $row->body !== null && $row->body !== '' ? mb_strimwidth((string) $row->body, 0, 240, '…') : 'Imported from ' . $origin,
            tone: $failed ? TimelineTone::Warning : TimelineTone::Neutral,
            icon: match ($row->kind) {
                'call' => 'phone',
                'note' => 'sticky-note',
                default => 'activity',
            },
            sequence: (int) $row->id,
        );
    }
}
