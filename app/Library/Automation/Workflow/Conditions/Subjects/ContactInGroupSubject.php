<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;

/**
 * `contact.in_group` — which contact group this contact belongs to.
 *
 * The value is the contact's own `group_id`; the operand is the group being
 * asked about. Comparison is by id, so this subject reads nothing beyond the
 * contact row it was already given.
 *
 * TENANCY IS NOT DECIDED HERE. Whether the operand group belongs to the
 * workflow's Business is a question about the OPERAND, not the value, so it is
 * answered where the operand is known to be trustworthy: at compile
 * (WorkflowCompiler) and again at evaluation (IfElseNodeExecutor), both against
 * real rows. A subject that silently compared against a foreign group id would
 * leak nothing by itself — ids are opaque — but it would let a workflow assert
 * something about another Business's data, so both gates exist.
 */
final readonly class ContactInGroupSubject implements ConditionSubject
{
    public function key(): string
    {
        return 'contact.in_group';
    }

    public function valueType(): string
    {
        return 'reference';
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return ConditionOperator::forReference();
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        return $contact->group_id === null ? null : (int) $contact->group_id;
    }
}
