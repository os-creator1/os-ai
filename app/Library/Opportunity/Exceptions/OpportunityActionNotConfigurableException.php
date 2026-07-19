<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::configureAction() when the locked
 * Opportunity's persisted recommended_action has no configurable action_key
 * (RFC-002 §13.1) — this phase supports only add_phone — or the registry
 * entry is missing the metadata configuration requires. The row is left
 * completely untouched.
 */
class OpportunityActionNotConfigurableException extends OpportunityException
{
}
