<?php

namespace App\Events\Usage;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessWalletCredited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $walletId,
        public readonly int $ledgerEntryId,
        public readonly int $amountMicro,
    ) {
    }
}
