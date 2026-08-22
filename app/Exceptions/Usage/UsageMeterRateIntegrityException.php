<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * RFC-005 Amendment 1 §F — thrown by UsageWalletManager::reserve() (Slice
 * 2, not yet wired in Slice 1) when the rate referenced by a UsageMeter's
 * own active_rate_id does not genuinely belong to that same meter (wrong
 * meter_key or mismatched currency) — expected structurally unreachable
 * given the composite same-meter/same-currency database constraints
 * (RFC-005 Amendment 1 §D), and retained only as a final defensive check.
 */
class UsageMeterRateIntegrityException extends RuntimeException
{
    public function __construct(public readonly string $meterKey, public readonly int $rateId)
    {
        parent::__construct("Rate #{$rateId} does not belong to UsageMeter '{$meterKey}'.");
    }
}
