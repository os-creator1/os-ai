<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;

/**
 * `contact.first_name`, `last_name`, `email`, `company`.
 *
 * These are not columns. In this product a contact carries a phone number and a
 * group, and everything else lives in that group's custom fields — so an
 * identity attribute is read by TAG from the contact's own group, using the same
 * four tags ContactDirectory treats as a contact's name card. That set is
 * mirrored deliberately, so a workflow condition and the contacts list can never
 * disagree about what "email" is.
 *
 * A group without the tag reads as empty, which is the honest answer: the
 * attribute is not set for this contact, and `is_empty` should say so rather
 * than the journey failing.
 */
final readonly class ContactIdentitySubject implements ConditionSubject
{
    public function __construct(
        private string $key,
        private string $tag,
        private ConditionSubjectRegistry $registry,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function valueType(): string
    {
        return 'text';
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return ConditionOperator::forText();
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        return $this->registry->identityValue($contact, $this->tag);
    }
}
