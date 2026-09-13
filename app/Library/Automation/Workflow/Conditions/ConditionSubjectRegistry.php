<?php

namespace App\Library\Automation\Workflow\Conditions;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactCustomFieldSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactIdentitySubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactInGroupSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactSubscribedSubject;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\ContactGroupFields;
use App\Models\Contacts;
use Illuminate\Support\Collection;

/**
 * Automations V2 §11 — the single authority on what an If/Else may ask about.
 *
 * A condition is a `{subject, operator, operand}` triple and nothing else. The
 * subject must be a key this class knows; the operator must be one the subject
 * declares; the operand is validated against the subject's type. There is no
 * expression language, no dynamic column name, no customer-authored predicate
 * and no raw SQL anywhere in the path — which is the whole reason conditions go
 * through a registry instead of through a query builder.
 *
 * WHAT IS DELIBERATELY ABSENT. `contact.replied_since_enrollment` is V2-F: it
 * needs the inbound producer and automation-send tagging that do not exist yet,
 * and a subject that silently reads false would be worse than one that does not
 * exist. Opportunity, Forms, Booking, Payment, Tag and Pipeline subjects are
 * excluded by §10 and are not stubbed here either.
 *
 * READS ARE BOUNDED. Evaluating five conditions must not cost five round trips,
 * so the two things every subject needs — the contact's stored values, and the
 * field definitions of the contact's group — are each read once and memoized per
 * contact. A subject then answers from memory. That is what keeps §11's "query
 * count stays bounded" true regardless of how many conditions a branch carries.
 */
class ConditionSubjectRegistry
{
    /**
     * The identity attributes, mapped to the field tags they are stored under.
     *
     * These four tags are the same set ContactDirectory treats as a contact's
     * name card, deliberately kept identical so a workflow condition and the
     * contacts list never disagree about what "email" means. They are restated
     * rather than imported because that constant is private, and because this
     * map carries something it does not: the subject key each tag answers to.
     */
    public const IDENTITY_SUBJECTS = [
        'contact.first_name' => 'FIRST_NAME',
        'contact.last_name' => 'LAST_NAME',
        'contact.email' => 'EMAIL',
        'contact.company' => 'COMPANY',
    ];

    public const SUBSCRIBED = 'contact.subscribed';

    public const IN_GROUP = 'contact.in_group';

    /** `contact.custom_field:{field_id}` — the only parameterised subject. */
    public const CUSTOM_FIELD_PREFIX = 'contact.custom_field:';

    /** §11 — operands are bounded strings. */
    public const MAX_OPERAND_LENGTH = 255;

    /** @var array<int, array<int, string>> contact id => field id => value */
    private array $valueCache = [];

    /** @var array<int, Collection<int, ContactGroupFields>> group id => fields */
    private array $groupFieldCache = [];

    /**
     * Resolve a stored subject key to the object that can read it.
     *
     * Returns null for anything unregistered — an unknown key, a malformed
     * custom-field key, or a subject belonging to a later slice. Callers treat
     * null as "refuse", never as "assume text".
     */
    public function find(string $key): ?ConditionSubject
    {
        if (array_key_exists($key, self::IDENTITY_SUBJECTS)) {
            return new ContactIdentitySubject($key, self::IDENTITY_SUBJECTS[$key], $this);
        }

        if ($key === self::SUBSCRIBED) {
            return new ContactSubscribedSubject();
        }

        if ($key === self::IN_GROUP) {
            return new ContactInGroupSubject();
        }

        $fieldId = self::customFieldId($key);

        return $fieldId === null ? null : new ContactCustomFieldSubject($fieldId, $this);
    }

    public function isRegistered(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * Whether a key is a subject THIS PRODUCT KNOWS, decided without touching
     * the database.
     *
     * Static and pure so NodeTypeRegistry — which is documented as never
     * touching the database, and is unit-tested without a schema — can refuse an
     * unknown subject at validation. Whether the thing a known key POINTS AT
     * exists and belongs to the Business is a different question, answered
     * against real rows by WorkflowCompiler and again at execution.
     */
    public static function isKnownSubjectKey(string $key): bool
    {
        return array_key_exists($key, self::IDENTITY_SUBJECTS)
            || $key === self::SUBSCRIBED
            || $key === self::IN_GROUP
            || self::customFieldId($key) !== null;
    }

    /**
     * The operators a key accepts, when that can be known without a query.
     *
     * A custom field's family depends on the field's own type, so this returns
     * null for one and the compiler decides. Everything else is fixed by the
     * subject itself.
     *
     * @return list<ConditionOperator>|null
     */
    public static function staticOperatorsFor(string $key): ?array
    {
        if (array_key_exists($key, self::IDENTITY_SUBJECTS)) {
            return ConditionOperator::forText();
        }

        if ($key === self::SUBSCRIBED) {
            return ConditionOperator::forBoolean();
        }

        if ($key === self::IN_GROUP) {
            return ConditionOperator::forReference();
        }

        return null;
    }

    /**
     * The field id in a `contact.custom_field:{id}` key, or null if this is not
     * one. Strict: only digits, only a positive id — `custom_field:abc` and
     * `custom_field:0` are not subjects.
     */
    public static function customFieldId(string $key): ?int
    {
        if (! str_starts_with($key, self::CUSTOM_FIELD_PREFIX)) {
            return null;
        }

        $raw = substr($key, strlen(self::CUSTOM_FIELD_PREFIX));

        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /** @return list<string> the non-parameterised keys, for the builder and tests. */
    public function staticKeys(): array
    {
        return [...array_keys(self::IDENTITY_SUBJECTS), self::SUBSCRIBED, self::IN_GROUP];
    }

    /**
     * Whether this operator is legal for this subject. The subject decides; this
     * is only the lookup, so a subject can never be evaluated with an operator it
     * does not declare.
     */
    public function allows(string $key, ConditionOperator $operator): bool
    {
        $subject = $this->find($key);

        return $subject !== null && in_array($operator, $subject->allowedOperators(), true);
    }

    /**
     * The contact's stored custom-field values, read once per contact.
     *
     * @return array<int, string> field id => value
     */
    public function valuesFor(Contacts $contact): array
    {
        $id = (int) $contact->id;

        if (! array_key_exists($id, $this->valueCache)) {
            $contact->loadMissing('contactsFields');

            $this->valueCache[$id] = $contact->contactsFields
                ->mapWithKeys(fn ($row): array => [(int) $row->field_id => (string) $row->value])
                ->all();
        }

        return $this->valueCache[$id];
    }

    /**
     * The field definitions of one contact group, read once per group.
     *
     * @return Collection<int, ContactGroupFields>
     */
    public function fieldsForGroup(?int $groupId): Collection
    {
        if ($groupId === null) {
            return collect();
        }

        if (! array_key_exists($groupId, $this->groupFieldCache)) {
            $this->groupFieldCache[$groupId] = ContactGroupFields::query()
                ->where('contact_group_id', $groupId)
                ->get();
        }

        return $this->groupFieldCache[$groupId];
    }

    /**
     * An identity value, read by tag from the contact's OWN group.
     *
     * Scoping by the contact's group is what makes this tenant-safe without a
     * second check: a contact's stored values only ever reference fields of the
     * group it belongs to, and that group belongs to one Business.
     */
    public function identityValue(Contacts $contact, string $tag): string
    {
        $field = $this->fieldsForGroup($contact->group_id === null ? null : (int) $contact->group_id)
            ->first(fn ($row): bool => (string) $row->tag === $tag);

        if ($field === null) {
            return '';
        }

        return $this->valuesFor($contact)[(int) $field->id] ?? '';
    }

    /** Forget memoized reads. Used between evaluations in long-lived processes. */
    public function flush(): void
    {
        $this->valueCache = [];
        $this->groupFieldCache = [];
    }
}
