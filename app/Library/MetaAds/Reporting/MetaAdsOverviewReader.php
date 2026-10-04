<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsConfig;
use App\Models\MetaAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract 24 §5 / §9 — the Overview KPI block, from cached
 * CAMPAIGN-level daily rows only (ad-set and ad rows are never summed into
 * account KPIs). Bounded work: the period, comparison and month-to-date
 * aggregates (each 1-3 queries) regardless of row counts. Never calls a
 * provider.
 */
final class MetaAdsOverviewReader
{
    public function __construct(
        private readonly MetaAdsMetricQueries $metrics,
        private readonly MetaAdsBudgetReader $budget,
        private readonly MetaAdsConfig $config,
    ) {
    }

    public function read(MetaAdsAccount $account, ?GoogleAdsPeriod $period = null, ?CarbonImmutable $now = null): MetaAdsOverview
    {
        $period ??= MetaAdsPeriod::resolve(null, $account, $now);
        $totals = $this->totals($account, $period);
        $pacing = $this->budget->pacing($account, $now);
        $target = $this->budget->target($account);
        $cpr = $totals->costPerResultMicros();
        $type = $this->metrics->results()->chosenType($account);

        return new MetaAdsOverview(
            period: $period,
            currencyCode: (string) $account->currency_code,
            hasData: $totals->hasData(),
            totals: $totals,
            spendMicros: $totals->spendMicros,
            impressions: $totals->impressions,
            clicks: $totals->clicks,
            linkClicks: $totals->linkClicks,
            resultTypeChosen: $type !== null,
            resultTypeUnset: $type === null,
            resultType: $type,
            resultTypeLabel: $this->metrics->results()->chosenLabel($account),
            results: $totals->results,
            costPerResultMicros: $cpr,
            ctr: $totals->ctr(),
            resultValue: $totals->resultValue(),
            pacing: $pacing,
            projectedMonthEndSpendMicros: $pacing->projectedMicros,
            projectionLowConfidence: $pacing->projectionLowConfidence,
            targetCostPerResultMicros: $target,
            targetStatus: $this->budget->costPerResultStatus($cpr, $target),
            comparison: $this->comparison($account, $period, $totals),
            coverage: $this->coverage($account, $period, $totals),
            lastSuccessfulSyncAt: $account->last_successful_sync_at,
            dataThroughDate: $account->data_through_date,
            lastSyncFailureCode: $account->last_sync_failure_code,
            account: [
                'uid' => (string) $account->uid,
                'name' => $account->name,
                'currency_code' => (string) $account->currency_code,
                'time_zone' => (string) $account->time_zone,
                'account_status' => $account->account_status,
                'selected' => $account->isSelected(),
            ],
        );
    }

    /**
     * Whether the cached window can answer this period without a provider
     * call: at least one cached day AND the period starts inside the window
     * the sync keeps (sync.metrics_lookback_days ending at data_through_date,
     * or today when unknown).
     *
     * @return array{days_in_period: int, days_with_data: int, covered: bool}
     */
    public function coverage(MetaAdsAccount $account, GoogleAdsPeriod $period, ?MetaAdsMetricTotals $totals = null): array
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

    private function totals(MetaAdsAccount $account, GoogleAdsPeriod $period): MetaAdsMetricTotals
    {
        return $this->metrics->totals($account, MetaAdsLevel::Campaign, $period->fromDate(), $period->toDate());
    }

    /** @return array<string, mixed>|null */
    private function comparison(MetaAdsAccount $account, GoogleAdsPeriod $period, MetaAdsMetricTotals $current): ?array
    {
        $previousPeriod = $period->comparison();
        $previous = $this->totals($account, $previousPeriod);

        if (! $previous->hasData()) {
            return null;
        }

        return [
            'period' => $previousPeriod->toArray(),
            'spend_micros' => $previous->spendMicros,
            'results' => $previous->results,
            'cost_per_result_micros' => $previous->costPerResultMicros(),
            'impressions' => $previous->impressions,
            'link_clicks' => $previous->linkClicks,
            'ctr' => $previous->ctr(),
            'spend_change' => self::change($current->spendMicros, $previous->spendMicros),
            'results_change' => self::change($current->results, $previous->results),
            'cost_per_result_change' => self::change($current->costPerResultMicros(), $previous->costPerResultMicros()),
            'impressions_change' => self::change($current->impressions, $previous->impressions),
            'link_clicks_change' => self::change($current->linkClicks, $previous->linkClicks),
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
