<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;

/**
 * `contact.subscribed` — the compliance-relevant condition (§11).
 *
 * Read straight from the contact's own status, with no interpretation: anything
 * that is not the canonical subscribed value is not subscribed. That direction
 * matters. If the value were ever unexpected, reading it as "subscribed" would
 * let a branch text somebody who had opted out, so the fallback is the safe one.
 *
 * This does not replace the send-time consent check — SendSmsNodeExecutor
 * re-reads consent at the action boundary regardless of what any condition
 * decided earlier.
 */
final readonly class ContactSubscribedSubject implements ConditionSubject
{
    public function key(): string
    {
        return 'contact.subscribed';
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
        return $contact->status === Contacts::STATUS_SUBSCRIBE;
    }
}
