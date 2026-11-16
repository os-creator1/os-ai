<?php

namespace App\Library\Contacts;

use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Writes the append-only `contact_edit_events` rows for a MANUAL edit: it
 * compares what the contact looked like before and after the real write and
 * records only the fields that actually changed. Called only from the manual
 * edit endpoints — automations, imports and API writes never reach it, so the
 * timeline can neither double an automation event nor invent history.
 */
final class ContactEditRecorder
{
    public const FIELDS = ['name', 'email', 'phone', 'group'];

    /** @return array{name: ?string, email: ?string, phone: ?string, group: ?string} */
    public function snapshot(Contacts $contact): array
    {
        $contact = Contacts::query()->find($contact->id) ?? $contact;
        $name = trim(trim((string) $contact->getValueByTag('FIRST_NAME')) . ' ' . trim((string) $contact->getValueByTag('LAST_NAME')));
        $group = ContactGroups::query()->where('business_id', $contact->business_id)->whereKey($contact->group_id)->value('name');

        return [
            'name' => $name === '' ? null : $name,
            'email' => app(ContactEmailIdentity::class)->status($contact)['email'],
            'phone' => filled($contact->phone) ? (string) $contact->phone : null,
            'group' => $group !== null ? (string) $group : null,
        ];
    }

    /**
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $after
     * @return int rows written
     */
    public function record(Business $business, Contacts $contact, array $before, array $after, ?int $actorUserId): int
    {
        $rows = [];
        $now = now();

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $before) || ! array_key_exists($field, $after) || $before[$field] === $after[$field]) {
                continue;
            }

            $rows[] = [
                'business_id' => $business->id,
                'contact_id' => $contact->id,
                'actor_user_id' => $actorUserId,
                'field' => $field,
                'old_value' => $before[$field],
                'new_value' => $after[$field],
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('contact_edit_events')->insert($rows);
        }

        return count($rows);
    }
}
