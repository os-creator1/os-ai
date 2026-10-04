<?php

namespace App\Library\GoogleAds\Reporting;

use Carbon\CarbonInterface;

/**
 * The Overview KPI block for one period (contract §9). Every metric is
 * null when the account has no cached rows in the period; `totals` carries
 * the same figures plus the derived values (cplMicros(), conversionRate(),
 * conversionValue()). Money is micros in `currencyCode`.
 *
 * `comparison` (null when the comparison window has no data):
 *   period {key, from, to, timezone, days}, spend_micros, conversions,
 *   cpl_micros, clicks, impressions, conversion_rate, and *_change ratios
 *   ((current - previous) / previous, e.g. 0.25 = +25%), null when either
 *   side is null or the previous value is 0.
 *
 * Freshness inputs come from the account row (never a provider call).
 */
final class GoogleAdsOverview
{
    /**
     * @param  array<string, mixed>|null  $comparison
     * @param  array{days_in_period: int, days_with_data: int, covered: bool}  $coverage
     */
    public function __construct(
        public readonly GoogleAdsPeriod $period,
        public readonly string $currencyCode,
        public readonly bool $hasData,
        public readonly GoogleAdsMetricTotals $totals,
        public readonly ?int $spendMicros,
        /** Google's conversions: a decimal string (may be fractional) or null. */
        public readonly ?string $googleConversions,
        public readonly ?int $cplMicros,
        public readonly ?float $conversionRate,
        /** Only when the summed conversion value is > 0 (else null). */
        public readonly ?string $conversionValue,
        public readonly ?int $clicks,
        public readonly ?int $impressions,
        public readonly GoogleAdsPacing $pacing,
        /** (month-to-date spend / days with data) x days in month; null with zero days. */
        public readonly ?int $projectedMonthEndSpendMicros,
        public readonly bool $projectionLowConfidence,
        public readonly ?array $comparison,
        public readonly array $coverage,
        public readonly ?CarbonInterface $lastSuccessfulSyncAt,
        public readonly ?CarbonInterface $dataThroughDate,
        public readonly ?string $lastSyncFailureCode,
    ) {
    }
}
