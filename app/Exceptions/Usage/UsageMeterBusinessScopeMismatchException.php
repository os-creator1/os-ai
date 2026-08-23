<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * RFC-005 Amendment 1 §F — thrown by UsageWalletManager::reserve() (Slice
 * 2, not yet wired in Slice 1) when a resolved UsageMeter is scoped to a
 * specific Business other than the one attempting to use it. A meter's
 * own business_id is a safety rail against consuming the wrong meter,
 * never a substitute for correct Business resolution upstream (RFC-005
 * Amendment 1 §M).
 */
class UsageMeterBusinessScopeMismatchException extends RuntimeException
{
    public function __construct(public readonly string $meterKey, public readonly int $businessId)
    {
        parent::__construct("UsageMeter '{$meterKey}' is scoped to a different Business than #{$businessId}.");
    }
}
