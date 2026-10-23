<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A contact's tasks (e.g. the automation's "call the lead") on their timeline. Scoped to the Business and the contact. */
final class ContactTasksSource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        $contact = $subject->contact;

        if ($contact === null || (int) $contact->business_id !== (int) $subject->business->id) {
            return [];
        }

        $timezone = $subject->business->timezone ?: config('app.timezone');

        return DB::table('contact_tasks')
            ->where('business_id', $subject->business->id)->where('contact_id', $contact->id)
            ->orderByDesc('created_at')->limit($limit)->get()
            ->map(function (object $row) use ($timezone): TimelineItem {
                $due = $row->due_at === null ? null : CarbonImmutable::parse((string) $row->due_at, 'UTC')->setTimezone($timezone)->format('M j, g:i A');
                $open = $row->status === 'open';

                return new TimelineItem(
                    key: 'contact_task:'.$row->id,
                    kind: TimelineItemKind::Activity,
                    at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
                    title: ($open ? 'Task: ' : 'Task done: ').$row->title,
                    detail: $open ? ($due !== null ? 'Due '.$due : 'Open') : null,
                    tone: $open ? TimelineTone::Warning : TimelineTone::Success,
                    icon: $row->kind === 'call' ? 'phone-call' : 'check-square',
                    sequence: (int) $row->id,
                );
            })->all();
    }
}
