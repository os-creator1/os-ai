<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\ContactGroupFields;
use App\Models\Contacts;

/**
 * `contact.custom_field:{field_id}` — the only parameterised subject.
 *
 * THE RULE THAT MAKES THIS SAFE, from §11: a contact outside the field's group
 * READS AS EMPTY. A custom field belongs to exactly one contact group, and a
 * contact stores values only for its own group's fields. So when the configured
 * field is not one of this contact's group's fields, this returns empty rather
 * than reaching for a value — which means a condition degrades to "not set"
 * instead of either throwing or, far worse, returning a value that belongs to
 * somebody else's group.
 *
 * The field id is still checked against the Business at compile and again at
 * evaluation. This subject's empty-read is the third layer, not the first: it is
 * what keeps a legitimately-configured workflow working when a contact simply
 * sits in a different group of the same Business.
 *
 * The operator family follows the field's own type — date fields compare as
 * dates, everything else as text — so a date field cannot be asked `contains`.
 */
final readonly class ContactCustomFieldSubject implements ConditionSubject
{
    public function __construct(
        private int $fieldId,
        private ConditionSubjectRegistry $registry,
    ) {
    }

    public function key(): string
    {
        return ConditionSubjectRegistry::CUSTOM_FIELD_PREFIX . $this->fieldId;
    }

    public function fieldId(): int
    {
        return $this->fieldId;
    }

    public function valueType(): string
    {
        $field = ContactGroupFields::query()->find($this->fieldId);

        if ($field === null) {
            return 'text';
        }

        return ContactGroupFields::getControlNameByType((string) $field->type) === 'date' ? 'date' : 'text';
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return $this->valueType() === 'date'
            ? ConditionOperator::forDate()
            : ConditionOperator::forText();
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        // The field must be one of the CONTACT'S OWN group's fields. Anything
        // else — another group of the same Business, or another Business
        // entirely — reads as empty and never as somebody else's value.
        $belongs = $this->registry
            ->fieldsForGroup($contact->group_id === null ? null : (int) $contact->group_id)
            ->contains(fn ($row): bool => (int) $row->id === $this->fieldId);

        if (! $belongs) {
            return '';
        }

        return $this->registry->valuesFor($contact)[$this->fieldId] ?? '';
    }
}
