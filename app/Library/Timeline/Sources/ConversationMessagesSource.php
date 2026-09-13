<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineDirection;
use App\Enums\Timeline\TimelineItemKind;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use App\Models\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The conversation's own messages — `chat_box_messages`, the canonical
 * conversation history every inbound path (legacy webhooks, managed inbound
 * since #285) and every two-way inbox send writes.
 *
 * Tenancy is the conversation's: the ChatBox was already resolved by uid AND
 * business_id before a timeline is built, and its messages carry no tenancy of
 * their own (Slice 2B §3).
 */
final class ConversationMessagesSource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        $conversation = $subject->conversation;

        if ($conversation === null || (int) $conversation->business_id !== (int) $subject->business->id) {
            return [];
        }

        return DB::table('chat_box_messages')
            ->where('box_id', $conversation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'message', 'media_url', 'direction', 'send_by', 'created_at'])
            ->map(fn (object $row): TimelineItem => new TimelineItem(
                key: 'conversation_message:' . $row->id,
                kind: TimelineItemKind::Message,
                at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
                body: trim((string) $row->message) === '' ? null : (string) $row->message,
                direction: $this->direction($row),
                media: self::mediaList($row->media_url),
                sequence: (int) $row->id,
            ))
            ->all();
    }

    /**
     * `direction` is the canonical field. A historical row written before it
     * existed falls back to `send_by` — 'to' is the external side — exactly as
     * ContactDirectory reads the same table.
     */
    private function direction(object $row): TimelineDirection
    {
        if ($row->direction !== null) {
            return $row->direction === Reports::DIRECTION_INCOMING ? TimelineDirection::Inbound : TimelineDirection::Outbound;
        }

        return $row->send_by === 'to' ? TimelineDirection::Inbound : TimelineDirection::Outbound;
    }

    /**
     * Managed inbound joins several media URLs with a comma; everything else
     * stores one.
     *
     * @return list<string>
     */
    public static function mediaList(?string $mediaUrl): array
    {
        if ($mediaUrl === null || trim($mediaUrl) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $mediaUrl)), static fn (string $url): bool => $url !== ''));
    }
}
