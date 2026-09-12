<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §11 — the closed set of condition operators.
 *
 * Bounded on purpose. There is no expression language, no raw SQL fragment and
 * no customer-authored predicate anywhere in a workflow: a condition is a
 * {subject, operator, operand} triple where the subject comes from a code-backed
 * registry (V2-A) and the operator is one of these cases. Evaluation is
 * read-only and uses bound parameters.
 */
enum ConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';
    case Before = 'before';
    case After = 'after';
    case OnDate = 'on_date';

    /**
     * Whether the operator needs an operand. The validator uses this to refuse
     * `is_empty` with a value, and `equals` without one — both of which would
     * otherwise read as "probably fine" and behave surprisingly.
     */
    public function requiresOperand(): bool
    {
        return ! in_array($this, [
            self::IsEmpty,
            self::IsNotEmpty,
            self::IsTrue,
            self::IsFalse,
        ], true);
    }

    /** Operators valid for a free-text subject. */
    public static function forText(): array
    {
        return [self::Equals, self::NotEquals, self::Contains, self::NotContains, self::IsEmpty, self::IsNotEmpty];
    }

    /** Operators valid for a yes/no subject. */
    public static function forBoolean(): array
    {
        return [self::IsTrue, self::IsFalse];
    }

    /** Operators valid for a date subject. */
    public static function forDate(): array
    {
        return [self::Before, self::After, self::OnDate, self::IsEmpty, self::IsNotEmpty];
    }

    /** Operators valid for a reference subject such as a contact group. */
    public static function forReference(): array
    {
        return [self::Equals, self::NotEquals];
    }
}
