<?php

namespace App\Events\Usage;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A spend was REFUSED because a spending limit (Business or Workspace spend cap) or the
 * balance stopped it. Raised at the wallet write authority from the same once-per-period
 * marker that alerts the billing contact (markSpendingLimitAlert), after the
 * transaction, so it is the hard-cap moment and never repeats within the period.
 */
final class BusinessSpendingLimitReached
{
    use Dispatchable;

    /** @param string $reason business_spend_cap | workspace_spend_cap | insufficient_balance */
    public function __construct(public readonly int $businessId, public readonly string $reason)
    {
    }
}
