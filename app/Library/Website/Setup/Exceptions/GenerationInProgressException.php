<?php

namespace App\Library\Website\Setup\Exceptions;

use DomainException;

/**
 * Independent-review correction round 2 — thrown when an answer mutation
 * (autosave, back navigation, gallery/custom-section change) is attempted
 * while QuestionnaireResponse.generation_started_at is set: an active AI
 * generation call is already reading this response's answers, and they
 * must never change underneath it. The wizard controller catches this and
 * shows a friendly "generation in progress" message rather than silently
 * applying or dropping the mutation.
 */
final class GenerationInProgressException extends DomainException
{
}
