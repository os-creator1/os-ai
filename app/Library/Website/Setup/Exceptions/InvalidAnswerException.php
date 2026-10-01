<?php

namespace App\Library\Website\Setup\Exceptions;

use DomainException;

/**
 * Independent-review correction round — thrown by QuestionnaireAnswerValidator
 * for a submitted answer that fails the pinned step definition's own
 * rules (a forged option, a missing required boolean choice, an
 * oversized repeatable-group submission, and so on).
 * WebsiteWizardController::autosaveAnswer() catches this and redisplays
 * the same step with the message, exactly like AnswerRevisionConflictException.
 */
final class InvalidAnswerException extends DomainException
{
}
