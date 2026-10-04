<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * The Budget page's facts. Three distinct things, never conflated:
 *   - `pacing`: month-to-date spend vs the Business MONTHLY target
 *   - the CPL block: current cost per conversion vs the Business target CPL
 *   - `campaigns`: this month's spend next to each campaign's DAILY budget
 *     (GoogleAdsCampaignRow::dailyBudgetMicros), which is Google's setting
 *     and not the monthly target.
 */
final class GoogleAdsBudgetOverview
{
    /** @param  array<int, GoogleAdsCampaignRow>  $campaigns */
    public function __construct(
        public readonly string $currencyCode,
        public readonly GoogleAdsPeriod $monthPeriod,
        public readonly GoogleAdsPacing $pacing,
        public readonly ?int $targetCplMicros,
        public readonly GoogleAdsPeriod $cplPeriod,
        public readonly ?int $currentCplMicros,
        /** null when there is a target but no current CPL (no conversions). */
        public readonly ?GoogleAdsCplStatus $cplStatus,
        public readonly array $campaigns,
    ) {
    }
}
