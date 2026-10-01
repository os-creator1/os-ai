<?php

namespace App\Library\BusinessEmail;

use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Where a Contact's email address comes from, and the rules for trusting it.
 *
 * A Contact has no email column: a person's email is a custom field value
 * (`contacts_custom_field` joined to `contact_group_fields` with tag
 * `EMAIL`), exactly as ContactDirectory reads it. Nothing guarantees one
 * value per Contact or uniqueness across Contacts, so:
 *
 *  - a Contact with NO valid address, or with SEVERAL DIFFERENT valid
 *    addresses, resolves to null — the sender refuses rather than choosing
 *    one (never silently guess a recipient);
 *  - every address is normalized (trim, lowercase) and strictly validated,
 *    with control characters rejected so a stored value can never inject a
 *    header.
 */
final class BusinessEmailContactResolver
{
    private const EMAIL_TAG = 'EMAIL';

    public static function normalize(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $address = strtolower(trim($address));

        if ($address === '' || strlen($address) > 191 || preg_match('/[\x00-\x1F\x7F\s,;<>]/', $address) === 1) {
            return null;
        }

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /** The Contact's one valid address, or null when none / ambiguous. */
    public function singleAddressFor(Contacts $contact): ?string
    {
        $values = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)
            ->where('f.tag', self::EMAIL_TAG)
            ->limit(20)
            ->pluck('v.value')
            ->all();

        return $this->onlyDistinctValid($values);
    }

    /**
     * A bounded list of this Business's most recent Contacts that have
     * exactly one valid address, for the manual-send picker. Two queries,
     * constant in the number of Contacts.
     *
     * @return list<array{uid: string, label: string, email: string}>
     */
    public function emailableContacts(Business $business, int $limit = 50): array
    {
        $rows = DB::table('contacts as c')
            ->join('contacts_custom_field as v', 'v.contact_id', '=', 'c.id')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('c.business_id', $business->id)
            ->where('f.tag', self::EMAIL_TAG)
            ->orderByDesc('c.id')
            ->limit($limit * 4)
            ->get(['c.id', 'c.uid', 'v.value']);

        $byContact = [];

        foreach ($rows as $row) {
            $byContact[(int) $row->id]['uid'] = (string) $row->uid;
            $byContact[(int) $row->id]['values'][] = (string) $row->value;
        }

        $picked = [];

        foreach ($byContact as $id => $entry) {
            $email = $this->onlyDistinctValid($entry['values']);

            if ($email !== null) {
                $picked[$id] = ['uid' => $entry['uid'], 'email' => $email];
            }

            if (count($picked) >= $limit) {
                break;
            }
        }

        if ($picked === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->whereIn('v.contact_id', array_keys($picked))
            ->whereIn('f.tag', ['FIRST_NAME', 'LAST_NAME'])
            ->orderBy('v.id')
            ->get(['v.contact_id', 'f.tag', 'v.value']) as $row) {
            $value = trim((string) $row->value);

            if ($value !== '') {
                $names[(int) $row->contact_id][(string) $row->tag] ??= $value;
            }
        }

        $list = [];

        foreach ($picked as $id => $entry) {
            $name = trim(($names[$id]['FIRST_NAME'] ?? '') . ' ' . ($names[$id]['LAST_NAME'] ?? ''));

            $list[] = [
                'uid' => $entry['uid'],
                'label' => $name !== '' ? $name . ' — ' . $entry['email'] : $entry['email'],
                'email' => $entry['email'],
            ];
        }

        return $list;
    }

    /** @param array<int, mixed> $values */
    private function onlyDistinctValid(array $values): ?string
    {
        $valid = [];

        foreach ($values as $value) {
            $normalized = self::normalize(is_string($value) ? $value : null);

            if ($normalized !== null) {
                $valid[$normalized] = true;
            }
        }

        return count($valid) === 1 ? (string) array_key_first($valid) : null;
    }
}
