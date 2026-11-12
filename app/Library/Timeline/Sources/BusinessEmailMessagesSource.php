<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineDirection;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Conversations\UnifiedEmailInbox;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use App\Models\BusinessEmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * A Contact's email (the email ledger), interleaved with their SMS in the one
 * Conversations timeline.
 *
 * Only a Contact the conversation was SAFELY resolved to (one Contact on the
 * number — never a name match) contributes email, and only the ledger rows the
 * email pipeline itself already linked to that Contact (`contact_id`). Rows are
 * read through the Business and the actor's Location reach. With no Contact it
 * adds nothing.
 */
final class BusinessEmailMessagesSource implements TimelineSource
{
    public function __construct(private readonly UnifiedEmailInbox $inbox)
    {
    }

    public function recent(TimelineSubject $subject, int $limit): array
    {
        if ($subject->contact === null || (int) $subject->contact->business_id !== (int) $subject->business->id || Auth::id() === null
            // Email is Conversations content: the same permission that opens the inbox.
            || ! Gate::allows('chat_box')) {
            return [];
        }

        return $this->inbox->visible($subject->business, (int) Auth::id())
            ->where('contact_id', $subject->contact->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BusinessEmailMessage $message): TimelineItem => self::item($message))
            ->all();
    }

    /** One ledger row as a timeline message (also used for an unmatched thread). */
    public static function item(BusinessEmailMessage $message): TimelineItem
    {
        $inbound = $message->direction === 'inbound';
        $status = $message->status->value;
        $failed = $status === 'failed';

        return new TimelineItem(
            key: 'email_message:' . $message->id,
            kind: TimelineItemKind::Message,
            at: CarbonImmutable::instance(UnifiedEmailInbox::activityAt($message)),
            title: (string) $message->subject,
            body: trim((string) $message->body_text) === '' ? null : (string) $message->body_text,
            direction: $inbound ? TimelineDirection::Inbound : TimelineDirection::Outbound,
            channel: 'email',
            via: $inbound ? ($message->from_name ?: $message->from_email) : $message->from_email,
            detail: $failed ? 'Not delivered' : ($status === 'unconfirmed' ? 'Delivery not confirmed' : null),
            tone: $failed ? TimelineTone::Warning : TimelineTone::Neutral,
            sequence: (int) $message->id,
        );
    }
}
