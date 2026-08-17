<?php

namespace App\Events\Usage;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessFundingAttemptSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $fundingAttemptId,
        public readonly int $businessId,
        public readonly string $purpose,
        public readonly int $amountMicro,
    ) {
    }
}
