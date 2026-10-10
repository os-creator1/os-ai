<?php

namespace App\Library\Acquisition\Economics;

/**
 * Class enrolment for a local Business (a kids ceramics studio). One-off
 * workshops count as exactly one session; recurring enrolment multiplies by the
 * owner's typical number of paid sessions — so a one-off business is never
 * forced into recurring-student economics.
 */
final class ClassEnrollmentCalculator extends ContributionCalculator
{
    public const KEY = 'class_enrollment';

    public function key(): string
    {
        return self::KEY;
    }

    protected function priceKey(): string
    {
        return 'revenue_per_session';
    }

    protected function costKey(): string
    {
        return 'variable_cost_per_session';
    }

    protected function unitsKey(): ?string
    {
        return null;
    }

    protected function unitNoun(): string
    {
        return 'session';
    }

    protected function unitsPerCustomer(array $answers, array $unknown): ?float
    {
        $type = $answers['enrollment_type'] ?? null;

        if ($type === 'one_off') {
            return 1.0;
        }

        if ($type !== 'recurring') {
            return null;
        }

        $sessions = $this->number($answers, $unknown, 'paid_sessions');

        return $sessions !== null && $sessions > 0 ? $sessions : null;
    }

    public function inputs(): array
    {
        return [
            'revenue_per_session' => ['type' => 'money', 'label' => 'Typical price of one class or session', 'help' => 'What one child (or one booking) pays for a single class.'],
            'variable_cost_per_session' => ['type' => 'money', 'label' => 'Direct cost of running one session', 'help' => 'Clay, glazing, firing, materials and any staff cost that exists only because the session happens.'],
            'enrollment_type' => ['type' => 'choice', 'label' => 'Is enrolment usually one-off or recurring?', 'help' => 'One-off workshop or birthday: one session. Weekly classes: recurring.', 'options' => ['one_off', 'recurring']],
            'paid_sessions' => ['type' => 'count', 'label' => 'If recurring: typical number of paid sessions per child', 'help' => 'Ignored for one-off enrolment.'],
        ] + $this->targetInputs('booking');
    }
}
