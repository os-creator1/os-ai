<?php

declare(strict_types=1);

namespace App\Events\Opportunity;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OpportunityExecutionFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $opportunityId,
        public readonly int $businessId,
        public readonly int $actorUserId,
        public readonly int $actionExecutionId,
        public readonly string $actionKey,
        public readonly string $safeErrorSummary,
    ) {
    }
}
