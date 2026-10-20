<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use App\Library\Voice\VoiceCallHistory;
use Carbon\CarbonImmutable;

/**
 * Browser calling — a person's calls on the same timeline every other domain joins. Calls come from the
 * canonical voice_calls table (never from messages) and are keyed by the subject's Business, so a call a
 * different Business had with the same number never appears here.
 */
final class VoiceCallActivitySource implements TimelineSource
{
    public function __construct(private readonly VoiceCallHistory $history)
    {
    }

    public function recent(TimelineSubject $subject, int $limit): array
    {
        return $this->history->forPerson($subject->business, $subject->contact, $subject->numberVariants(), $limit)
            ->map(function ($call): TimelineItem {
                $d = VoiceCallHistory::describe($call);

                return new TimelineItem(
                    key: 'voice_call:' . $call->id,
                    kind: TimelineItemKind::Activity,
                    at: CarbonImmutable::parse(($call->initiated_at ?? $call->created_at)->toDateTimeString(), config('app.timezone')),
                    title: $d['title'],
                    detail: $d['detail'],
                    tone: TimelineTone::from($d['tone']),
                    icon: $call->direction === 'inbound' ? 'phone-incoming' : 'phone-outgoing',
                    sequence: (int) $call->id,
                );
            })
            ->all();
    }
}
