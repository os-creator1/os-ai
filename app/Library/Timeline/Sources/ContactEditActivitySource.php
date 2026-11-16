<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineItemKind;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manual edits to the one Contact the conversation resolves to — name, email,
 * phone, group — from the append-only `contact_edit_events` rows. Nothing
 * earlier than the first recorded row is shown, and with no single Contact
 * there is nothing to show.
 */
final class ContactEditActivitySource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        $contact = $subject->contact;

        if ($contact === null || (int) $contact->business_id !== (int) $subject->business->id) {
            return [];
        }

        return DB::table('contact_edit_events')
            ->where('business_id', $subject->business->id)
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'field', 'old_value', 'new_value', 'created_at'])
            ->map(fn (object $row): TimelineItem => $this->item($row))
            ->all();
    }

    private function item(object $row): TimelineItem
    {
        $label = match ($row->field) {
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            default => 'Group',
        };
        $old = $this->show((string) $row->field, $row->old_value);
        $new = $this->show((string) $row->field, $row->new_value);

        [$title, $detail] = match (true) {
            $old === null && $new !== null => [$label . ' added', $new],
            $old !== null && $new === null => [$label . ' removed', 'Was ' . $old],
            default => [$label . ' changed', $old . ' → ' . $new],
        };

        return new TimelineItem(
            key: 'contact_edit:' . $row->id,
            kind: TimelineItemKind::Activity,
            at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
            title: $title,
            detail: $detail,
            icon: 'pencil',
            sequence: (int) $row->id,
        );
    }

    private function show(string $field, mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $field === 'phone' ? '+' . ltrim($value, '+') : $value;
    }
}
