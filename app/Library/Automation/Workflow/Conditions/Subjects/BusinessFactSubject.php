<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\Contacts;
use Closure;

/**
 * A yes/no fact about the Business (or the contact's standing with it) read at the moment the condition runs:
 * "is it business hours / quiet hours now", "is a review link / questionnaire link set up", "does this contact still
 * have an open deal". Read-only; the Business is always the enrollment's own, so no cross-tenant read is possible.
 */
final readonly class BusinessFactSubject implements ConditionSubject
{
    /** @param Closure(Business, Contacts, AutomationEnrollment): bool $read */
    public function __construct(private string $key, private Closure $read)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function valueType(): string
    {
        return 'boolean';
    }

    public function allowedOperators(): array
    {
        return ConditionOperator::forBoolean();
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        $business = Business::query()->whereKey((int) $enrollment->business_id)->first();

        if ($business === null || (int) $contact->business_id !== (int) $business->id) {
            return false;
        }

        return (bool) ($this->read)($business, $contact, $enrollment);
    }
}
