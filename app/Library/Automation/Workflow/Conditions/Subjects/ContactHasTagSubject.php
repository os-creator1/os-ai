<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;

/**
 * `contact.has_tag:{tag_id}` — does this contact currently wear this tag?
 *
 * A parameterised boolean subject, like `contact.custom_field:{id}` is a
 * parameterised text/date one: the tag is part of the key, so the condition is
 * the plain `{subject, operator}` pair with no operand. "Has tag" is `is_true`
 * and "Does not have tag" is `is_false`.
 *
 * The contact's membership is read from `contact_tags`, once per contact
 * (ConditionSubjectRegistry::tagIdsFor), filtered on the contact's OWN Business —
 * a membership row carries its Business, and a composite foreign key ties it to a
 * tag of that same Business, so a tag id of another Business can never read as
 * held. Whether the tag in the KEY belongs to the workflow's Business is the
 * compiler's question and IfElseNodeExecutor's again at execution, the same two
 * gates every referencing subject has.
 */
final readonly class ContactHasTagSubject implements ConditionSubject
{
    public function __construct(
        private int $tagId,
        private ConditionSubjectRegistry $registry,
    ) {
    }

    public function key(): string
    {
        return ConditionSubjectRegistry::HAS_TAG_PREFIX . $this->tagId;
    }

    public function valueType(): string
    {
        return 'boolean';
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return ConditionOperator::forBoolean();
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        return in_array($this->tagId, $this->registry->tagIdsFor($contact), true);
    }
}
