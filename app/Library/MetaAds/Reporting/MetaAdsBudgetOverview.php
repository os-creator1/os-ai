<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPacing;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;

/**
 * The Meta budget facts. Three distinct things, never conflated:
 *   - `pacing`: month-to-date spend vs the Meta MONTHLY target
 *     (meta_ads_accounts.monthly_budget_target_micros; provider-specific, M6)
 *   - the cost-per-result block: current cost per result (chosen result type)
 *     vs the Meta target cost per result
 *   - `campaigns`: this month's spend next to each campaign's own DAILY /
 *     LIFETIME budget (MetaAdsCampaignRow::dailyBudgetMinor), which is Meta's
 *     setting and not the monthly target.
 */
final class MetaAdsBudgetOverview
{
    /** @param  array<int, MetaAdsCampaignRow>  $campaigns */
    public function __construct(
        public readonly string $currencyCode,
        public readonly GoogleAdsPeriod $monthPeriod,
        public readonly GoogleAdsPacing $pacing,
        public readonly ?int $targetCostPerResultMicros,
        public readonly GoogleAdsPeriod $costPerResultPeriod,
        public readonly bool $resultTypeChosen,
        public readonly ?int $currentCostPerResultMicros,
        /** null when there is a target but no current cost per result. */
        public readonly ?GoogleAdsCplStatus $costPerResultStatus,
        public readonly array $campaigns,
    ) {
    }
}
