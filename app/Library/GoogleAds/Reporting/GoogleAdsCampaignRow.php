<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;

/**
 * One campaign line for a period. View-facing fields are the public
 * properties; Google's own ids live ONLY in `internal` and must never be
 * rendered or put in a URL (rows are addressed by `uid`).
 *
 * Metric fields are null when the campaign has no cached rows in the period
 * (absence is not zero). `dailyBudgetMicros` is Google's DAILY campaign
 * budget (a shared budget can span campaigns) and is NOT the Business
 * monthly target.
 */
final class GoogleAdsCampaignRow
{
    /**
     * @param  array{external_campaign_id: string}  $internal
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly GoogleAdsEntityStatus $status,
        public readonly ?string $channelType,
        public readonly ?int $dailyBudgetMicros,
        public readonly ?bool $budgetShared,
        public readonly GoogleAdsMetricTotals $totals,
        public readonly array $internal,
    ) {
    }

    public function spendMicros(): ?int
    {
        return $this->totals->spendMicros;
    }

    public function cplMicros(): ?int
    {
        return $this->totals->cplMicros();
    }

    public function conversionRate(): ?float
    {
        return $this->totals->conversionRate();
    }
}
