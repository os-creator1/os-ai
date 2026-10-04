<?php

namespace App\Library\Automation\Workflow\Conditions;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactBusinessFieldSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactCustomFieldSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactHasTagSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactIdentitySubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactInGroupSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactRepliedSinceEnrollmentSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactSubscribedSubject;
use App\Library\Automation\Workflow\Conditions\Subjects\TriggerFactSubject;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Enums\CustomFields\CustomFieldType;
use App\Library\CustomFields\CustomFieldValueCodec;
use App\Models\ContactGroupFields;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 * `contact.replied_since_enrollment` arrived with V2-F, once the inbound producer
 * and automation-send tagging it depends on existed — it is boolean, reads the
 * Business's conversation history strictly after the enrollment, and is
 * same-Business only (ContactRepliedSinceEnrollmentSubject).
 *
 * `contact.has_tag:{tag_id}` arrived with the Contact Tags integration: a
 * parameterised boolean ("has tag" / "does not have tag"), read from the
 * canonical `contact_tags` membership.
 *
 * `opportunity.*`, `document.*`, `payment.status` and `appointment.status` arrived
 * with the cross-domain actions: they read the fact behind the journey (and the
 * Contact's own deal) live through FactConditionReader, and each is a fixed key with
 * fixed operators and a closed set of operand values.
 *
 * WHAT IS DELIBERATELY ABSENT. Free-form Forms answers and Booking details are not
 * subjects, and there is no expression language: a condition is only ever a key this
 * class knows.
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

    /** V2-F — has the contact written to the Business since entering this journey? */
    public const REPLIED_SINCE_ENROLLMENT = 'contact.replied_since_enrollment';

    /*
     * The fact-backed subjects: what the CRM deal, the document, the payment and the
     * appointment behind a journey currently say (FactConditionReader). Each is a
     * fixed key with fixed operators — still no expression language.
     */
    public const OPPORTUNITY_STAGE = 'opportunity.stage';

    public const OPPORTUNITY_STATUS = 'opportunity.status';

    public const DOCUMENT_STATUS = 'document.status';

    public const DOCUMENT_SIGNED = 'document.signed';

    public const DOCUMENT_PAID = 'document.paid';

    public const PAYMENT_STATUS = 'payment.status';

    public const APPOINTMENT_STATUS = 'appointment.status';

    /**
     * Each fact subject's value type, operator family and — for the text ones — the
     * closed set of values its operand may be. The values are the owning domains' own
     * enum values, restated because this table must answer without a database.
     *
     * @var array<string, array{type: string, operators: string, values?: list<string>}>
     */
    public const FACT_SUBJECTS = [
        self::OPPORTUNITY_STAGE => ['type' => 'reference', 'operators' => 'reference'],
        self::OPPORTUNITY_STATUS => ['type' => 'text', 'operators' => 'reference', 'values' => ['open', 'won', 'lost']],
        self::DOCUMENT_STATUS => ['type' => 'text', 'operators' => 'reference', 'values' => ['draft', 'sent', 'signed', 'paid', 'expired', 'void']],
        self::DOCUMENT_SIGNED => ['type' => 'boolean', 'operators' => 'boolean'],
        self::DOCUMENT_PAID => ['type' => 'boolean', 'operators' => 'boolean'],
        self::PAYMENT_STATUS => ['type' => 'text', 'operators' => 'reference', 'values' => ['created', 'requires_action', 'processing', 'succeeded', 'failed', 'canceled']],
        self::APPOINTMENT_STATUS => ['type' => 'text', 'operators' => 'reference', 'values' => ['scheduled', 'cancelled', 'completed', 'no_show']],
    ];


    /**
     * `contact.custom_field:{field_id}` — the LEGACY contact-group field subject.
     * Still evaluated so already-published workflow versions keep working, but
     * the Builder no longer offers it for new conditions (see BUSINESS_FIELD_PREFIX).
     */
    public const CUSTOM_FIELD_PREFIX = 'contact.custom_field:';

    /**
     * `contact.field:{key}` — a Business-wide Custom Field by its stable key.
     * The vocabulary new conditions use; same key as `{{contact.<key>}}`.
     */
    public const BUSINESS_FIELD_PREFIX = 'contact.field:';

    /** `contact.has_tag:{tag_id}` — the other parameterised subject (boolean). */
    public const HAS_TAG_PREFIX = 'contact.has_tag:';

    /** §11 — operands are bounded strings. */
    public const MAX_OPERAND_LENGTH = 255;

    /** @var array<int, array<int, string>> contact id => field id => value */
    private array $valueCache = [];

    /** @var array<int, list<int>> contact id => tag ids */
    private array $tagCache = [];

    /** @var array<int, Collection<int, ContactGroupFields>> group id => fields */
    private array $groupFieldCache = [];

    /** @var array<int, array<string, CustomFieldDefinition>> business id => key => definition */
    private array $definitionCache = [];

    /** @var array<int, array<string, mixed>> contact id => key => comparable value */
    private array $businessFieldCache = [];

    /**
     * Resolve a stored subject key to the object that can read it.
     *
     * Returns null for anything unregistered — an unknown key, a malformed
     * custom-field key, or a subject belonging to a later slice. Callers treat
     * null as "refuse", never as "assume text".
     */
    public function find(string $key, ?int $businessId = null): ?ConditionSubject
    {
        $fieldKey = self::businessFieldKey($key);

        if ($fieldKey !== null) {
            // A Business-wide field needs the Business to resolve its type, so
            // without one it is not a readable subject (refuse, never guess).
            return $businessId === null ? null : new ContactBusinessFieldSubject($fieldKey, $businessId, $this);
        }

        if (array_key_exists($key, self::IDENTITY_SUBJECTS)) {
            return new ContactIdentitySubject($key, self::IDENTITY_SUBJECTS[$key], $this);
        }

        if ($key === self::SUBSCRIBED) {
            return new ContactSubscribedSubject();
        }

        if ($key === self::IN_GROUP) {
            return new ContactInGroupSubject();
        }

        if ($key === self::REPLIED_SINCE_ENROLLMENT) {
            return new ContactRepliedSinceEnrollmentSubject();
        }

        $factSubject = $this->factSubject($key);

        if ($factSubject !== null) {
            return $factSubject;
        }

        $tagId = self::tagId($key);

        if ($tagId !== null) {
            return new ContactHasTagSubject($tagId, $this);
        }

        $fieldId = self::customFieldId($key);

        return $fieldId === null ? null : new ContactCustomFieldSubject($fieldId, $this);
    }

    private function factSubject(string $key): ?ConditionSubject
    {
        $meta = self::FACT_SUBJECTS[$key] ?? null;

        if ($meta === null) {
            return null;
        }

        $reader = app(FactConditionReader::class);

        $read = match ($key) {
            self::OPPORTUNITY_STAGE => fn (Contacts $contact, $enrollment) => $reader->opportunityStage($enrollment, $contact),
            self::OPPORTUNITY_STATUS => fn (Contacts $contact, $enrollment) => $reader->opportunityStatus($enrollment, $contact),
            self::DOCUMENT_STATUS => fn (Contacts $contact, $enrollment) => $reader->documentStatus($enrollment),
            self::DOCUMENT_SIGNED => fn (Contacts $contact, $enrollment) => $reader->documentSigned($enrollment),
            self::DOCUMENT_PAID => fn (Contacts $contact, $enrollment) => $reader->documentPaid($enrollment),
            self::PAYMENT_STATUS => fn (Contacts $contact, $enrollment) => $reader->paymentStatus($enrollment),
            default => fn (Contacts $contact, $enrollment) => $reader->appointmentStatus($enrollment),
        };

        return new TriggerFactSubject(
            $key,
            $meta['type'],
            $meta['operators'] === 'boolean' ? ConditionOperator::forBoolean() : ConditionOperator::forReference(),
            $read,
        );
    }

    /**
     * Whether this fact subject's OPERAND is acceptable, decided without a database:
     * a stage id is a positive integer and every other text subject takes one of its
     * closed set of values. Null when it is (or when the key is not a fact subject).
     */
    public static function operandProblem(string $key, mixed $operand): ?string
    {
        if ($key === self::OPPORTUNITY_STAGE) {
            return (is_int($operand) || (is_string($operand) && ctype_digit($operand))) && (int) $operand > 0
                ? null
                : 'needs a stage to compare against';
        }

        $values = self::FACT_SUBJECTS[$key]['values'] ?? null;

        if ($values === null) {
            return null;
        }

        return is_string($operand) && in_array($operand, $values, true) ? null : 'needs one of its known values';
    }

    /**
     * Whether the workflow's trigger can ever give this subject something to read —
     * a document condition on a workflow that does not start from a document reads
     * nothing, ever, so publishing it would be a silent "no" on every journey.
     * Returns a sentence, or null when the pairing makes sense.
     */
    public static function triggerProblem(string $key, ?\App\Enums\Automation\Workflow\WorkflowTriggerType $trigger): ?string
    {
        $needs = match ($key) {
            self::DOCUMENT_STATUS, self::DOCUMENT_SIGNED, self::DOCUMENT_PAID => ['document', fn ($t): bool => $t->hasDocumentFact()],
            self::PAYMENT_STATUS => ['payment', fn ($t): bool => $t->isPayment()],
            self::APPOINTMENT_STATUS => ['appointment', fn ($t): bool => $t->isAppointment()],
            default => null,
        };

        if ($needs === null || ($trigger !== null && $needs[1]($trigger))) {
            return null;
        }

        return sprintf('checks %s details, which this workflow\'s trigger does not provide', $needs[0] === 'document' ? 'proposal or invoice' : $needs[0]);
    }

    public function isRegistered(string $key, ?int $businessId = null): bool
    {
        return $this->find($key, $businessId) !== null;
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
            || $key === self::REPLIED_SINCE_ENROLLMENT
            || array_key_exists($key, self::FACT_SUBJECTS)
            || self::tagId($key) !== null
            || self::businessFieldKey($key) !== null
            || self::customFieldId($key) !== null;
    }

    /**
     * The field key in a `contact.field:{key}` subject, or null if this is not
     * one. Strict: the same shape a Custom Field key is generated in.
     */
    public static function businessFieldKey(string $key): ?string
    {
        if (! str_starts_with($key, self::BUSINESS_FIELD_PREFIX)) {
            return null;
        }

        $raw = substr($key, strlen(self::BUSINESS_FIELD_PREFIX));

        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $raw) === 1 ? $raw : null;
    }

    /** A Business's Custom Field definition by key (archived included), read once per Business. */
    public function businessFieldDefinition(int $businessId, string $key): ?CustomFieldDefinition
    {
        if (! array_key_exists($businessId, $this->definitionCache)) {
            $this->definitionCache[$businessId] = CustomFieldDefinition::query()
                ->where('business_id', $businessId)
                ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)
                ->get()
                ->keyBy('key')
                ->all();
        }

        return $this->definitionCache[$businessId][$key] ?? null;
    }

    /**
     * The Contact's value for a Custom Field in the form the evaluator compares:
     * numbers as floats, booleans as bools, option ids as strings, multi-select
     * as a list, dates as `Y-m-d` / `Y-m-d H:i:s`. Null when unset. Read once per Contact.
     */
    public function businessFieldValue(Contacts $contact, string $key): mixed
    {
        $id = (int) $contact->id;

        if (! array_key_exists($id, $this->businessFieldCache)) {
            $values = [];

            // Loads (and memoizes) the Business's definitions if not yet read.
            $this->businessFieldDefinition((int) $contact->business_id, $key);
            $definitions = collect($this->definitionCache[(int) $contact->business_id])->keyBy('id');

            $rows = CustomFieldValue::query()
                ->where('contact_id', $id)
                ->where('business_id', (int) $contact->business_id)
                ->get();

            foreach ($rows as $row) {
                $definition = $definitions->get($row->definition_id);

                if ($definition === null) {
                    continue;
                }

                $canonical = CustomFieldValueCodec::fromRow($definition->fieldType(), $row);

                $values[$definition->key] = in_array($definition->fieldType(), [CustomFieldType::Number, CustomFieldType::Currency], true) && $canonical !== null
                    ? (float) $canonical
                    : $canonical;
            }

            $this->businessFieldCache[$id] = $values;
        }

        return $this->businessFieldCache[$id][$key] ?? null;
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

        if ($key === self::SUBSCRIBED || $key === self::REPLIED_SINCE_ENROLLMENT || self::tagId($key) !== null) {
            return ConditionOperator::forBoolean();
        }

        if ($key === self::IN_GROUP) {
            return ConditionOperator::forReference();
        }

        if (isset(self::FACT_SUBJECTS[$key])) {
            return self::FACT_SUBJECTS[$key]['operators'] === 'boolean' ? ConditionOperator::forBoolean() : ConditionOperator::forReference();
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

    /**
     * The tag id in a `contact.has_tag:{id}` key, or null if this is not one.
     * Strict, like customFieldId(): only digits, only a positive id.
     */
    public static function tagId(string $key): ?int
    {
        if (! str_starts_with($key, self::HAS_TAG_PREFIX)) {
            return null;
        }

        $raw = substr($key, strlen(self::HAS_TAG_PREFIX));

        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /** @return list<string> the non-parameterised keys, for the builder and tests. */
    public function staticKeys(): array
    {
        return [...array_keys(self::IDENTITY_SUBJECTS), self::SUBSCRIBED, self::IN_GROUP, self::REPLIED_SINCE_ENROLLMENT, ...array_keys(self::FACT_SUBJECTS)];
    }

    /**
     * Whether this operator is legal for this subject. The subject decides; this
     * is only the lookup, so a subject can never be evaluated with an operator it
     * does not declare.
     */
    public function allows(string $key, ConditionOperator $operator, ?int $businessId = null): bool
    {
        $subject = $this->find($key, $businessId);

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
     * The ids of the tags a contact currently wears, read once per contact.
     *
     * Filtered on the contact's own Business, so a membership can only ever name
     * a tag of that Business.
     *
     * @return list<int>
     */
    public function tagIdsFor(Contacts $contact): array
    {
        $id = (int) $contact->id;

        if (! array_key_exists($id, $this->tagCache)) {
            $this->tagCache[$id] = $contact->business_id === null
                ? []
                : DB::table('contact_tags')
                    ->where('contact_id', $id)
                    ->where('business_id', (int) $contact->business_id)
                    ->pluck('tag_id')
                    ->map(fn ($tagId): int => (int) $tagId)
                    ->all();
        }

        return $this->tagCache[$id];
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
        $this->tagCache = [];
        $this->definitionCache = [];
        $this->businessFieldCache = [];
    }
}
