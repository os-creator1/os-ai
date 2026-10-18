<?php

namespace App\Library\MetaAds\Reporting;

/**
 * One campaign line for a period. View-facing fields are the public
 * properties; Meta's own ids are NOT carried (rows are addressed by `uid`).
 *
 * Metric fields live in `totals` and are null when the campaign has no cached
 * insight rows in the period (absence is not zero). Budgets are Meta's own
 * DAILY / LIFETIME budgets in MINOR units of `currencyCode` (cents; whole
 * units for zero-decimal currencies) and are NEVER the Business monthly
 * target. `status` is Meta's configured status (ACTIVE / PAUSED / DELETED /
 * ARCHIVED); `effectiveStatus` is the provider-reported delivery status
 * quoted as a fact.
 */
final class MetaAdsCampaignRow
{
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $effectiveStatus,
        public readonly ?string $objective,
        public readonly ?int $dailyBudgetMinor,
        public readonly ?int $lifetimeBudgetMinor,
        public readonly ?int $budgetRemainingMinor,
        public readonly ?string $startTime,
        public readonly ?string $stopTime,
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
