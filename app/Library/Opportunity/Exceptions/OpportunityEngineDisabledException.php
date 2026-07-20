<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown when a customer-initiated OpportunityManager entry point (or a
 * queued job's own defense-in-depth check) runs while
 * config('opportunity.enabled') is false (RFC-002 §14) — the master switch
 * means no runs, no executions, no queue UI. Never carries a model ID,
 * configured action value, or any other internal detail.
 */
class OpportunityEngineDisabledException extends OpportunityException
{
    public function __construct()
    {
        parent::__construct('Opportunity engine is disabled.');
    }
}
