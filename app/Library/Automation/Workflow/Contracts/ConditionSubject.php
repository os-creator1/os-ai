<?php

namespace App\Library\Automation\Workflow\Contracts;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;

/**
 * Automations V2 §11 — one thing an If/Else may ask about.
 *
 * Implemented by V2-A's subject registry. Declared here because the validator
 * (V2-0) must be able to reject an unknown subject, or an operator a subject does
 * not support, before anything is published.
 *
 * THE POINT OF THIS INTERFACE IS WHAT IT FORBIDS. A condition is a
 * {subject, operator, operand} triple where the subject is one of these
 * registered objects. There is no expression language, no raw SQL fragment, no
 * dynamic column name and no customer-authored predicate anywhere in a workflow.
 * Reading a value is this interface's only job, and it is read-only.
 */
interface ConditionSubject
{
    /** The stable key stored in a condition's `subject` field. */
    public function key(): string;

    /** What the operand is validated against: text, boolean, date, reference. */
    public function valueType(): string;

    /** @return list<ConditionOperator> the operators this subject accepts. */
    public function allowedOperators(): array;

    /**
     * Read the current value for this contact. Read-only: an evaluation must
     * never write, enqueue or call out.
     *
     * A subject that cannot resolve — a custom field belonging to a group this
     * contact is not in — reads as empty rather than throwing, so a condition
     * degrades to "not set" instead of breaking the journey.
     */
    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed;
}
