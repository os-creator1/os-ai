<?php

namespace App\Library\Timeline;

use App\Enums\Timeline\TimelineDirection;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use Carbon\CarbonImmutable;

/**
 * One thing that happened with a person, as a persisted row proves it.
 *
 * DOMAIN-NEUTRAL ON PURPOSE. Nothing here is SMS-shaped except the default
 * channel: an email is a Message on the `email` channel, a form submission or a
 * payment is an Activity with its own finished sentence. A new domain adds a
 * TimelineSource that produces these; the Conversations screen renders them
 * without being redesigned.
 *
 * NOTHING IS COPIED. The body is read from the canonical row at request time
 * (a conversation message, a sent report) and is never stored a second time.
 *
 * IDENTITY, NOT INFERENCE. `key` is the source row's own identity, and
 * `represents` names other items this one already stands for — a sent message
 * stamped with the automation step that sent it represents that step, so the
 * timeline shows the message once instead of the message and a card.
 */
final class TimelineItem
{
    /**
     * @param  string  $key  "{source}:{row id}", unique across the timeline
     * @param  string  $title  an Activity's finished, past-tense sentence; empty for a Message
     * @param  ?string  $body  a Message's text, as its canonical row stores it
     * @param  list<string>  $media  media URLs attached to a Message
     * @param  ?string  $via  who sent an outbound Message when it was not a person in the inbox
     * @param  ?string  $detail  one short secondary line: a reason, a delivery problem, a group
     * @param  list<string>  $represents  keys of items this one already stands for
     * @param  int  $sequence  the source row's id, to order items that share a timestamp
     * @param  ?string  $retrySendUid  the stable logical-message id a Retry
     *                                 control posts back (item 4/5); null
     *                                 whenever this item offers no retry
     * @param  bool  $retryable  whether a Retry control should be offered at all —
     *                           never true without $retrySendUid also being set
     */
    public function __construct(
        public readonly string $key,
        public readonly TimelineItemKind $kind,
        public readonly CarbonImmutable $at,
        public readonly string $title = '',
        public readonly ?string $body = null,
        public readonly ?TimelineDirection $direction = null,
        public readonly string $channel = 'sms',
        public readonly array $media = [],
        public readonly ?string $via = null,
        public readonly ?string $detail = null,
        public readonly TimelineTone $tone = TimelineTone::Neutral,
        public readonly string $icon = 'info',
        public readonly array $represents = [],
        public readonly int $sequence = 0,
        public readonly ?string $retrySendUid = null,
        public readonly bool $retryable = false,
    ) {
    }

    public function isMessage(): bool
    {
        return $this->kind === TimelineItemKind::Message;
    }

    public function isInbound(): bool
    {
        return $this->direction === TimelineDirection::Inbound;
    }
}
