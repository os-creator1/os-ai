<?php

namespace App\Events\Usage;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessWalletBillingStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
    }
}
