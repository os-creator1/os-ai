<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityManager::retryFailedExecution() when an explicit
 * customer retry is not available for the locked Opportunity (RFC-002
 * §31) — wrong status, an inconsistent active-execution state, or no
 * failed execution matching the live action identity. The row is left
 * completely untouched.
 */
class OpportunityExecutionRetryNotAvailableException extends OpportunityException
{
    public function __construct()
    {
        parent::__construct('Opportunity execution retry is not available.');
    }
}
