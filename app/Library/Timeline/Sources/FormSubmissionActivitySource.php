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
 * Forms V1 (Blueprint §16) — a person's form submissions on the same
 * person-centric timeline every other domain joins by implementing
 * TimelineSource.
 *
 * CONTACT-KEYED, like DocumentActivitySource: nothing is shown unless the
 * conversation resolves to exactly one Contact of this Business, and only
 * submissions the canonical service LINKED to that Contact appear — a
 * submission recorded with an ambiguous or absent Contact is never
 * attributed to a person by guessing. Spam is never shown. The Business is
 * proven through the form's own Website (`websites.business_id`), not
 * trusted from the submission row. Only the finished sentence is read: the
 * submitted values stay on the submission and are not copied here.
 */
final class FormSubmissionActivitySource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        if ($subject->contact === null || (int) $subject->contact->business_id !== (int) $subject->business->id) {
            return [];
        }

        return DB::table('website_form_submissions as s')
            ->join('website_forms as f', 'f.id', '=', 's.website_form_id')
            ->join('websites as w', 'w.id', '=', 'f.website_id')
            ->where('w.business_id', (int) $subject->business->id)
            ->where('s.contact_id', (int) $subject->contact->id)
            ->where('s.is_spam', false)
            ->orderByDesc('s.id')
            ->limit($limit)
            ->get(['s.id', 's.created_at', 'f.name'])
            ->map(static fn (object $row): TimelineItem => new TimelineItem(
                key: 'form_submission:'.$row->id,
                kind: TimelineItemKind::Activity,
                at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
                title: 'Submitted '.$row->name,
                tone: TimelineTone::Neutral,
                icon: 'file-text',
                sequence: (int) $row->id,
            ))
            ->all();
    }
}
