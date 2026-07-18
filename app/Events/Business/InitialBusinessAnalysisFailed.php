<?php

namespace App\Events\Business;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InitialBusinessAnalysisFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string  $safeError  A customer-safe summary only — never a stack trace, SQL, or raw exception text.
     */
    public function __construct(
        public readonly int $onboardingId,
        public readonly int $analysisVersion,
        public readonly string $safeError,
    ) {
    }
}
