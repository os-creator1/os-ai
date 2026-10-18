<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPacing;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use Carbon\CarbonInterface;

/**
 * The Meta Overview KPI block for one period (contract 24 §5 / §9). Every
 * metric is null when the account has no cached campaign rows in the period;
 * `totals` carries the same figures plus derived values. Money is micros in
 * `currencyCode`.
 *
 * RESULTS: `resultTypeChosen` false => `results`, `costPerResultMicros`,
 * `resultValue` are null and the page says "Choose a result type"
 * (`resultTypeUnset` is the same fact, named for the view). `resultType` is
 * the stored action_type key (an internal key, not shown) and
 * `resultTypeLabel` its owner-facing label.
 *
 * `comparison` (null when the comparison window has no data): period,
 * spend_micros, results, cost_per_result_micros, impressions, link_clicks,
 * ctr, plus *_change ratios ((current - previous) / previous), null when
 * either side is null or the previous value is 0.
 *
 * `targetStatus` is the cost-per-result status vs the Meta target
 * (no_target when none; null when a target exists but there is no current
 * cost per result). `account` carries safe connected-account facts only.
 */
final class MetaAdsOverview
{
    /**
     * @param  array<string, mixed>|null  $comparison
     * @param  array{days_in_period: int, days_with_data: int, covered: bool}  $coverage
     * @param  array{uid: string, name: ?string, currency_code: string, time_zone: string, account_status: ?int, selected: bool}  $account
     */
    public function __construct(
        public readonly GoogleAdsPeriod $period,
        public readonly string $currencyCode,
        public readonly bool $hasData,
        public readonly MetaAdsMetricTotals $totals,
        public readonly ?int $spendMicros,
        public readonly ?int $impressions,
        public readonly ?int $clicks,
        public readonly ?int $linkClicks,
        public readonly bool $resultTypeChosen,
        public readonly bool $resultTypeUnset,
        public readonly ?string $resultType,
        public readonly ?string $resultTypeLabel,
        /** Chosen-type results: a decimal string or null (unavailable). */
        public readonly ?string $results,
        public readonly ?int $costPerResultMicros,
        public readonly ?float $ctr,
        /** Meta-reported value of the chosen type, only when > 0. */
        public readonly ?string $resultValue,
        public readonly GoogleAdsPacing $pacing,
        /** (month-to-date spend / days with data) x days in month; null with zero days. */
        public readonly ?int $projectedMonthEndSpendMicros,
        public readonly bool $projectionLowConfidence,
        public readonly ?int $targetCostPerResultMicros,
        public readonly ?GoogleAdsCplStatus $targetStatus,
        public readonly ?array $comparison,
        public readonly array $coverage,
        public readonly ?CarbonInterface $lastSuccessfulSyncAt,
        public readonly ?CarbonInterface $dataThroughDate,
        public readonly ?string $lastSyncFailureCode,
        public readonly array $account,
    ) {
    }
}
