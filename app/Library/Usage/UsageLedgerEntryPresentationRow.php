<?php

namespace App\Library\Usage;

use Carbon\CarbonImmutable;

/**
 * RFC-005 M2 contract §10/§13 (item 25 UsageBillingDashboardViewDataTest) —
 * read-only per-row presentation of one business_usage_ledger_entries row.
 * signedAmountMicro is derived from whichever delta column is non-zero for
 * that entry type — presentation only, never re-deriving a balance.
 */
final readonly class UsageLedgerEntryPresentationRow
{
    public function __construct(
        public string $entryType,
        public string $signedAmountMicro,
        public CarbonImmutable $createdAt,
        public ?string $featureKey,
    ) {
    }
}
