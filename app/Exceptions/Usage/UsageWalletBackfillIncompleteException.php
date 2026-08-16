<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletBackfillV1 when, after every Business has been
 * processed, a non-zero count of Businesses still lack a
 * business_usage_wallets row (M1 contract §9.2). Carries both the exact
 * remaining count and the run's own currency-resolution failure list
 * (Business id + BusinessCurrencyUnresolvableException::CLASSIFICATION_*),
 * so an operator can distinguish "genuinely not yet reached" from
 * "reached, but its currency needs correction" (Correction Round 1).
 */
class UsageWalletBackfillIncompleteException extends RuntimeException
{
    /**
     * @param array<int, array{business_id: int, classification: string}> $currencyResolutionFailures
     */
    public function __construct(
        public readonly int $remainingUnwalletedCount,
        public readonly array $currencyResolutionFailures = [],
    ) {
        parent::__construct(
            "Usage wallet backfill incomplete: {$remainingUnwalletedCount} Business(es) still have no business_usage_wallets row."
        );
    }
}
