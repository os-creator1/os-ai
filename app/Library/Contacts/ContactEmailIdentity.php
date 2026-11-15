<?php

namespace App\Library\Contacts;

use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE place a Contact's email address is written and classified.
 *
 * A Contact's canonical email is the value of its group's field tagged
 * `EMAIL` (what BusinessEmailContactResolver, Conversations, manual sending
 * and Automations all read). Older groups were created without that field,
 * which forced customers to invent a custom "Email" field; ensureField()
 * adds the built-in one on demand (idempotently, never touching existing
 * fields or values), and save() writes through it. Arbitrary custom fields
 * that merely happen to be called "Email" are never treated as authoritative:
 * only the tag counts.
 */
final class ContactEmailIdentity
{
    public const TAG = 'EMAIL';
    public const STATE_NONE = 'none';
    public const STATE_VALID = 'valid';
    public const STATE_AMBIGUOUS = 'ambiguous';

    public function __construct(private readonly BusinessEmailContactResolver $resolver)
    {
    }

    /** Built-in field for a group, created once when missing. Returns the field id. */
    public function ensureField(ContactGroups $group, string $tag = self::TAG): int
    {
        return DB::transaction(function () use ($group, $tag): int {
            $existing = DB::table('contact_group_fields')
                ->where('contact_group_id', $group->id)
                ->whereRaw('LOWER(tag) = ?', [strtolower($tag)])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return (int) $existing->id;
            }

            $definition = match ($tag) {
                self::TAG => ['label' => 'Email', 'type' => 'text'],
                'NOTES' => ['label' => 'Notes', 'type' => 'textarea'],
                'FIRST_NAME' => ['label' => 'First name', 'type' => 'text'],
                'LAST_NAME' => ['label' => 'Last name', 'type' => 'text'],
                'COMPANY' => ['label' => 'Company', 'type' => 'text'],
                default => throw new InvalidArgumentException('Unknown built-in contact field ' . $tag),
            };

            return (int) $group->contactGroupFields()->create([
                'contact_group_id' => $group->id,
                'type' => $definition['type'],
                'label' => $definition['label'],
                'tag' => $tag,
                'required' => false,
                'visible' => true,
            ])->id;
        });
    }

    /**
     * Save (or clear, with null/'') the canonical email.
     *
     * @throws InvalidArgumentException when a non-empty value is not a valid address
     */
    public function save(Contacts $contact, ?string $email): ?string
    {
        $email = trim((string) $email);
        $normalized = $email === '' ? null : BusinessEmailContactResolver::normalize($email);

        if ($email !== '' && $normalized === null) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        $group = $contact->contactGroup;

        if ($group === null) {
            throw new InvalidArgumentException('This contact has no group to store an email in.');
        }

        $fieldId = $this->ensureField($group);

        DB::transaction(function () use ($contact, $fieldId, $normalized): void {
            // Every EMAIL-tagged value this contact already has is made to agree, so the
            // resolver can never see two different addresses after an edit.
            $rows = DB::table('contacts_custom_field as v')
                ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
                ->where('v.contact_id', $contact->id)
                ->where('f.tag', self::TAG)
                ->pluck('v.id');

            if ($rows->isNotEmpty()) {
                ContactsCustomField::query()->whereIn('id', $rows)->update(['value' => $normalized]);
            }

            if (! DB::table('contacts_custom_field')->where('contact_id', $contact->id)->where('field_id', $fieldId)->exists()) {
                $value = new ContactsCustomField();
                $value->contact_id = $contact->id;
                $value->field_id = $fieldId;
                $value->value = $normalized;
                $value->save();
            }
        });

        return $normalized;
    }

    /** @return array{state: string, email: ?string} */
    public function status(Contacts $contact): array
    {
        $values = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)
            ->where('f.tag', self::TAG)
            ->pluck('v.value')
            ->map(fn ($value) => BusinessEmailContactResolver::normalize((string) $value))
            ->filter()
            ->unique()
            ->values();

        return match ($values->count()) {
            0 => ['state' => self::STATE_NONE, 'email' => null],
            1 => ['state' => self::STATE_VALID, 'email' => (string) $values->first()],
            default => ['state' => self::STATE_AMBIGUOUS, 'email' => null],
        };
    }
}
