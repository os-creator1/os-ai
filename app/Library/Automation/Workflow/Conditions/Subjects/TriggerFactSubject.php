<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use Closure;

/**
 * One condition that reads the fact behind a journey — the CRM deal, the document,
 * the payment, the appointment — through FactConditionReader.
 *
 * The subjects that share this class differ only in what they read and which
 * operators they accept, so the key, the value type, the operators and the reader are
 * data, not seven near-identical classes. Still read-only, still a registered key, and
 * still no expression language: `ConditionSubjectRegistry` is the only thing that
 * builds one.
 */
final readonly class TriggerFactSubject implements ConditionSubject
{
    /**
     * @param list<ConditionOperator> $operators
     * @param Closure(Contacts, AutomationEnrollment): mixed $reader
     */
    public function __construct(
        private string $key,
        private string $valueType,
        private array $operators,
        private Closure $reader,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function valueType(): string
    {
        return $this->valueType;
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return $this->operators;
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        return ($this->reader)($contact, $enrollment);
    }
}
