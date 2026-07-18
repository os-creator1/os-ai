<?php

namespace App\Events\Business;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InitialBusinessAnalysisRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $onboardingId,
        public readonly int $analysisVersion,
    ) {
    }
}
