<?php

namespace App\Library\Acquisition\Economics;

/**
 * Student enrolment for a Business that sells recurring lessons (tutoring).
 *
 *   contribution_per_lesson = lesson_price - variable_cost_per_lesson
 *   student_contribution_ltv = contribution_per_lesson x median_paid_lessons
 */
final class RecurringLessonsCalculator extends ContributionCalculator
{
    public const KEY = 'recurring_lessons';

    public function key(): string
    {
        return self::KEY;
    }

    protected function priceKey(): string
    {
        return 'lesson_price';
    }

    protected function costKey(): string
    {
        return 'variable_cost_per_lesson';
    }

    protected function unitsKey(): string
    {
        return 'median_paid_lessons';
    }

    protected function unitNoun(): string
    {
        return 'lesson';
    }

    public function inputs(): array
    {
        return [
            'lesson_price' => ['type' => 'money', 'label' => 'Average price charged per paid lesson', 'help' => 'What the student or parent pays for one lesson.'],
            'variable_cost_per_lesson' => ['type' => 'money', 'label' => 'Average direct cost of delivering one lesson', 'help' => 'Teacher pay and any cost that exists only because the lesson happens.'],
            'median_paid_lessons' => ['type' => 'count', 'label' => 'Typical number of paid lessons per student', 'help' => 'Use the middle value, not the best student. "I don\'t know yet" is fine; profitability then stays unknown.'],
        ] + $this->targetInputs('student');
    }
}
