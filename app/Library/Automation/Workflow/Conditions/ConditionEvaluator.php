<?php

namespace App\Library\Automation\Workflow\Conditions;

use App\Enums\Automation\Workflow\ConditionOperator;
use Illuminate\Support\Carbon;

/**
 * Automations V2 §11 — applying one operator to one already-read value.
 *
 * Deliberately pure: it takes a value and an operand and returns a boolean. It
 * reads no database, resolves no subject and knows nothing about tenancy, which
 * is what lets the comparison rules be reasoned about — and unit-tested —
 * without a schema.
 *
 * TWO DECISIONS WORTH STATING.
 *
 * Comparison is case-insensitive and trimmed for text. A workflow that checks
 * `email equals Bob@Example.com` should match a contact stored as
 * `bob@example.com `; a case-sensitive comparison here would be technically
 * defensible and would produce a stream of "the automation didn't fire" reports
 * that are impossible for a small business to diagnose.
 *
 * An unparseable date is not a match, whichever way the operator points. Not
 * `before`, not `after`, not `on`. Treating an unreadable value as "before
 * everything" would silently sweep every malformed row down the yes branch.
 */
class ConditionEvaluator
{
    public function matches(ConditionOperator $operator, mixed $value, mixed $operand): bool
    {
        return match ($operator) {
            ConditionOperator::IsTrue => $value === true,
            ConditionOperator::IsFalse => $value === false,

            ConditionOperator::IsEmpty => $this->isEmpty($value),
            ConditionOperator::IsNotEmpty => ! $this->isEmpty($value),

            ConditionOperator::Equals => $this->equals($value, $operand),
            ConditionOperator::NotEquals => ! $this->equals($value, $operand),

            ConditionOperator::Contains => $this->contains($value, $operand),
            ConditionOperator::NotContains => ! $this->contains($value, $operand),

            ConditionOperator::Before => $this->compareDates($value, $operand, 'before'),
            ConditionOperator::After => $this->compareDates($value, $operand, 'after'),
            ConditionOperator::OnDate => $this->compareDates($value, $operand, 'on'),

            ConditionOperator::GreaterThan => $this->compareNumbers($value, $operand, 'greater'),
            ConditionOperator::LessThan => $this->compareNumbers($value, $operand, 'less'),
        };
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            // A boolean subject is never "empty"; it is true or it is false.
            return false;
        }

        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }

    private function equals(mixed $value, mixed $operand): bool
    {
        // A numeric custom field reads as a float: compare as numbers, never as
        // truncated integers (180.5 is not 180).
        if (is_float($value)) {
            return is_numeric($operand) && abs($value - (float) $operand) < 1e-9;
        }

        // A reference subject compares as an integer id, so "12" and 12 are the
        // same group rather than two different ones.
        if (is_int($value) || (is_numeric($value) && is_numeric($operand) && ! is_string($value))) {
            return (int) $value === (int) $operand;
        }

        if ($value === null) {
            return $operand === null || trim((string) $operand) === '';
        }

        return $this->normalize($value) === $this->normalize($operand);
    }

    private function contains(mixed $value, mixed $operand): bool
    {
        $needle = $this->normalize($operand);

        if ($needle === '') {
            // "contains nothing" is not a useful question and must not be a
            // free pass that matches every contact.
            return false;
        }

        if (is_array($value)) {
            // A multi-select custom field: "includes" one of its stored options.
            return in_array($needle, array_map(fn ($item): string => $this->normalize($item), $value), true);
        }

        return str_contains($this->normalize($value), $needle);
    }

    /** An unreadable number is never greater or less than anything. */
    private function compareNumbers(mixed $value, mixed $operand, string $direction): bool
    {
        if (is_bool($value) || ! is_numeric($value) || is_bool($operand) || ! is_numeric($operand)) {
            return false;
        }

        return $direction === 'greater' ? (float) $value > (float) $operand : (float) $value < (float) $operand;
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function compareDates(mixed $value, mixed $operand, string $direction): bool
    {
        $left = $this->toDate($value);
        $right = $this->toDate($operand);

        if ($left === null || $right === null) {
            return false;
        }

        return match ($direction) {
            'before' => $left->lessThan($right),
            'after' => $left->greaterThan($right),
            default => $left->isSameDay($right),
        };
    }

    private function toDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
