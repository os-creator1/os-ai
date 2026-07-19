<?php

declare(strict_types=1);

namespace App\Events\Opportunity;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OpportunityReaffirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $opportunityId,
        public readonly int $businessId,
        public readonly int $runId,
        public readonly string $workerKey,
    ) {
    }
}
