<?php

namespace App\Events\Usage;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessUsageCommitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $reservationId,
        public readonly string $featureKey,
        public readonly int $finalAmountMicro,
        public readonly int $reservedAmountMicro,
    ) {
    }
}
