<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::recordExecutionResult() when the freshly
 * locked Opportunity/OpportunityActionExecution rows no longer match a
 * recordable state (RFC-002 §30.1 step 2, §30.2, §31) — a missing row, a
 * non-running execution, a non-in_progress Opportunity, an execution whose
 * bound action fields disagree with the live Opportunity, or an invalid
 * safe summary. Nothing is written.
 */
class InvalidOpportunityExecutionStateException extends OpportunityException
{
}
