<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §11 — how an If/Else combines its conditions.
 *
 * One level only, over at most WorkflowLimits::MAX_CONDITIONS_PER_BRANCH
 * conditions. Deeper logic is expressed by nesting If/Else steps on the canvas,
 * which stays readable, rather than by a nested boolean expression, which does
 * not — and which would invite a customer-authored expression language this
 * contract forbids.
 */
enum ConditionMatch: string
{
    /** Every condition must hold. */
    case All = 'all';

    /** At least one condition must hold. */
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All of these are true',
            self::Any => 'Any of these are true',
        };
    }
}
