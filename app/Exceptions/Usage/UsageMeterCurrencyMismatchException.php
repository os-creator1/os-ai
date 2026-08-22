<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * RFC-005 Amendment 1 §F — thrown by UsageWalletManager::reserve() (Slice
 * 2, not yet wired in Slice 1) when a resolved UsageMeter's own immutable
 * currency does not match the requesting Business's wallet currency. This
 * check is inherently per-execution and cannot be database-enforced,
 * unlike the rate/meter currency match (RFC-005 Amendment 1 §D) — never
 * conflated with UsageMeterRateIntegrityException.
 */
class UsageMeterCurrencyMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $meterKey,
        public readonly int $walletCurrencyId,
        public readonly int $meterCurrencyId,
    ) {
        parent::__construct(
            "UsageMeter '{$meterKey}' currency #{$meterCurrencyId} does not match wallet currency #{$walletCurrencyId}."
        );
    }
}
