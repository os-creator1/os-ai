<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::configureAction() when the caller-supplied
 * parameters payload does not match the action's registry-declared
 * parameter_rules exactly (RFC-002 §45 — reject, never clamp) — the row is
 * left completely untouched.
 */
class InvalidOpportunityActionParametersException extends OpportunityException
{
}
