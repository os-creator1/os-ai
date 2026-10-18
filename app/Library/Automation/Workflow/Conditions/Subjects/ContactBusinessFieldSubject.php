<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;

/**
 * `contact.field:{key}` — a Business-wide Custom Field, addressed by its STABLE
 * KEY (never a row id and never the label), so a published workflow keeps
 * reading the same field however it is renamed or reordered.
 *
 * The operator family follows the field's own type (text, number, date,
 * boolean, dropdown, multi-select), so a number can be asked "greater than" and
 * a date "before", but never the reverse. An unset field reads as empty (null),
 * so a condition degrades to "not set" rather than throwing; an ARCHIVED field
 * still reads (existing workflows stay deterministic).
 *
 * The definition is resolved against the CONTACT'S OWN Business, so a key that
 * belongs only to another Business reads as nothing.
 */
final readonly class ContactBusinessFieldSubject implements ConditionSubject
{
    public function __construct(
        private string $fieldKey,
        private int $businessId,
        private ConditionSubjectRegistry $registry,
    ) {
    }

    public function key(): string
    {
        return ConditionSubjectRegistry::BUSINESS_FIELD_PREFIX . $this->fieldKey;
    }

    public function definition(): ?CustomFieldDefinition
    {
        return $this->registry->businessFieldDefinition($this->businessId, $this->fieldKey);
    }

    public function valueType(): string
    {
        return $this->definition()?->fieldType()->conditionFamily() ?? 'text';
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return self::operatorsForFamily($this->valueType());
    }

    /** @return list<ConditionOperator> */
    public static function operatorsForFamily(string $family): array
    {
        return match ($family) {
            'number' => ConditionOperator::forNumber(),
            'date' => ConditionOperator::forDate(),
            'boolean' => ConditionOperator::forBoolean(),
            'select' => ConditionOperator::forSelect(),
            'multi' => ConditionOperator::forMulti(),
            default => ConditionOperator::forText(),
        };
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        if ((int) $contact->business_id !== $this->businessId) {
            return null;
        }

        return $this->registry->businessFieldValue($contact, $this->fieldKey);
    }
}
