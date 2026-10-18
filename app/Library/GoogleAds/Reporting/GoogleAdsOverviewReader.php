<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Models\GoogleAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 contract §9 — the Overview KPI block, from cached
 * CAMPAIGN-level daily rows only (keyword rows are never summed into account
 * KPIs). Bounded work: three aggregate queries (period, comparison,
 * month-to-date) regardless of row counts. Never calls a provider.
 */
final class GoogleAdsOverviewReader
{
    public function __construct(
        private readonly GoogleAdsMetricQueries $metrics,
        private readonly GoogleAdsBudgetReader $budget,
        private readonly GoogleAdsConfig $config,
    ) {
    }

    public function read(GoogleAdsAccount $account, ?GoogleAdsPeriod $period = null, ?CarbonImmutable $now = null): GoogleAdsOverview
    {
        $period ??= GoogleAdsPeriod::resolve(null, $account, $now);
        $totals = $this->totals($account, $period);
        $pacing = $this->budget->pacing($account, $now);

        return new GoogleAdsOverview(
            period: $period,
            currencyCode: (string) $account->currency_code,
            hasData: $totals->hasData(),
            totals: $totals,
            spendMicros: $totals->spendMicros,
            googleConversions: $totals->conversions,
            cplMicros: $totals->cplMicros(),
            conversionRate: $totals->conversionRate(),
            conversionValue: $totals->conversionValue(),
            clicks: $totals->clicks,
            impressions: $totals->impressions,
            pacing: $pacing,
            projectedMonthEndSpendMicros: $pacing->projectedMicros,
            projectionLowConfidence: $pacing->projectionLowConfidence,
            comparison: $this->comparison($account, $period, $totals),
            coverage: $this->coverage($account, $period, $totals),
            lastSuccessfulSyncAt: $account->last_successful_sync_at,
            dataThroughDate: $account->data_through_date,
            lastSyncFailureCode: $account->last_sync_failure_code,
        );
    }

    /**
     * Whether the cached window can answer this period without a provider
     * call: there is at least one cached day in it AND it starts inside the
     * window the sync keeps (sync.metrics_lookback_days ending at
     * data_through_date, or today when that is unknown). A range toggle only
     * ever re-filters these rows.
     *
     * @return array{days_in_period: int, days_with_data: int, covered: bool}
     */
    public function coverage(GoogleAdsAccount $account, GoogleAdsPeriod $period, ?GoogleAdsMetricTotals $totals = null): array
    {
        $totals ??= $this->totals($account, $period);
        $through = $account->data_through_date !== null
            ? CarbonImmutable::parse($account->data_through_date->format('Y-m-d'))
            : $period->today;
        $windowStart = $through->subDays($this->config->metricsLookbackDays() - 1);

        return [
            'days_in_period' => $period->days(),
            'days_with_data' => $totals->dayCount,
            'covered' => $totals->hasData() && $period->from->greaterThanOrEqualTo($windowStart),
        ];
    }

    private function totals(GoogleAdsAccount $account, GoogleAdsPeriod $period): GoogleAdsMetricTotals
    {
        return $this->metrics->totals($account, GoogleAdsMetricLevel::Campaign, $period->fromDate(), $period->toDate());
    }

    /** @return array<string, mixed>|null */
    private function comparison(GoogleAdsAccount $account, GoogleAdsPeriod $period, GoogleAdsMetricTotals $current): ?array
    {
        $previousPeriod = $period->comparison();
        $previous = $this->totals($account, $previousPeriod);

        if (! $previous->hasData()) {
            return null;
        }

        return [
            'period' => $previousPeriod->toArray(),
            'spend_micros' => $previous->spendMicros,
            'conversions' => $previous->conversions,
            'cpl_micros' => $previous->cplMicros(),
            'clicks' => $previous->clicks,
            'impressions' => $previous->impressions,
            'conversion_rate' => $previous->conversionRate(),
            'spend_change' => self::change($current->spendMicros, $previous->spendMicros),
            'conversions_change' => self::change($current->conversions, $previous->conversions),
            'cpl_change' => self::change($current->cplMicros(), $previous->cplMicros()),
            'clicks_change' => self::change($current->clicks, $previous->clicks),
        ];
    }

    /** (current - previous) / previous, or null when either side is null / previous is 0. */
    private static function change(int|string|null $current, int|string|null $previous): ?float
    {
        if ($current === null || $previous === null || bccomp((string) $previous, '0', 6) === 0) {
            return null;
        }

        return round((float) bcdiv(bcsub((string) $current, (string) $previous, 6), (string) $previous, 8), 4);
    }
}
