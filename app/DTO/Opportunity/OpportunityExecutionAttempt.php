<?php

declare(strict_types=1);

namespace App\DTO\Opportunity;

use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;

/**
 * Internal application DTO returned by
 * OpportunityManager::beginExecutionAttempt() (RFC-002 §30.1 steps 1-4) —
 * the freshly locked Opportunity paired with its now-running execution.
 * Never serialized or dispatched; it exists only to hand both rows back to
 * the caller within the same request/job without a second lookup.
 */
final readonly class OpportunityExecutionAttempt
{
    public function __construct(
        public Opportunity $opportunity,
        public OpportunityActionExecution $execution,
    ) {
    }
}
