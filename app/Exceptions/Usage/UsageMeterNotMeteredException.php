<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * RFC-005 Amendment 1 §F — thrown by UsageWalletManager::reserve() (Slice
 * 2, not yet wired in Slice 1) when a resolved UsageMeter has an
 * active_rate_id but is_metered is still false — closing the gap where a
 * meter could otherwise be charged despite never having been explicitly
 * activated for metering.
 */
class UsageMeterNotMeteredException extends RuntimeException
{
    public function __construct(public readonly string $meterKey)
    {
        parent::__construct("UsageMeter '{$meterKey}' is not metered.");
    }
}
