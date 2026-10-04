<?php

namespace App\Library\MetaAds\Reporting;

/**
 * One ad set line for a period. Addressed by `uid`; no Meta ids.
 * reach7d / frequency7d are the ad set's OWN trailing-7-day figures (reach and
 * frequency are not additive, never summed from days) and are labelled
 * "last 7 days" by the view; frequency7d is a decimal string (4 places) or null.
 */
final class MetaAdsAdSetRow
{
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $effectiveStatus,
        public readonly string $campaignUid,
        public readonly string $campaignName,
        public readonly ?int $dailyBudgetMinor,
        public readonly ?int $lifetimeBudgetMinor,
        public readonly ?string $optimizationGoal,
        public readonly ?string $bidStrategy,
        public readonly ?string $targetingSummary,
        public readonly ?int $reach7d,
        public readonly ?string $frequency7d,
        public readonly ?string $frequencyWindowEnd,
        public readonly string $currencyCode,
        public readonly MetaAdsMetricTotals $totals,
        public readonly int $localId = 0,
    ) {
    }

    public function spendMicros(): ?int
    {
        return $this->totals->spendMicros;
    }

    public function costPerResultMicros(): ?int
    {
        return $this->totals->costPerResultMicros();
    }
}
