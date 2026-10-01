<?php

namespace App\Enums\Questionnaire;

/**
 * Website Builder redesign — one Business's attempt at answering one
 * questionnaire. `in_progress` is the only non-terminal status; it is
 * the state the DB-enforced "at most one active session per Business per
 * questionnaire" guard (`active_definition_guard`) keys off.
 */
enum QuestionnaireResponseStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return $this !== self::InProgress;
    }
}
