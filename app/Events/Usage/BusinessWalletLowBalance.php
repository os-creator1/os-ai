<?php

namespace App\Events\Usage;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Business wallet fell to its low-balance threshold. Raised at the wallet write
 * authority (UsageWalletManager) from the SAME one-shot marker transition that sends
 * the owner their low-balance notice (low_balance_notified_at), so it fires once per
 * low episode, never per debit.
 */
final class BusinessWalletLowBalance
{
    use Dispatchable;

    public function __construct(public readonly int $businessId)
    {
    }
}
