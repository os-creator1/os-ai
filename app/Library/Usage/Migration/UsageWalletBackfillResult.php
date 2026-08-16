<?php

declare(strict_types=1);

namespace App\Library\Usage\Migration;

/**
 * Immutable outcome of a single UsageWalletBackfillV1::run() call. Only
 * ever constructed after every Business has been processed and the final
 * global unwalleted-count check has passed (M1 contract §9.2) — a
 * UsageWalletBackfillResult therefore always has
 * remainingUnwalletedCount === 0; a non-zero count is reported via
 * UsageWalletBackfillIncompleteException instead.
 */
final readonly class UsageWalletBackfillResult
{
    /**
     * @param array<int, array{business_id: int, classification: string}> $currencyResolutionFailures
     */
    public function __construct(
        public int $businessesProcessed,
        public int $walletsCreated,
        public int $remainingUnwalletedCount,
        public array $currencyResolutionFailures = [],
    ) {
    }
}
