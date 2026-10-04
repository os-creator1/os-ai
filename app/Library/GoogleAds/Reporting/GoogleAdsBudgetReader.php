<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Models\GoogleAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 contract §9 — reads the cached month-to-date facts and
 * hands them to the pure GoogleAdsPacingCalculator. Pacing is always about
 * the CURRENT calendar month in the account time zone, whatever period the
 * page is showing. Campaign-level daily rows only.
 */
final class GoogleAdsBudgetReader
{
    private ?GoogleAdsPacingCalculator $calculator = null;

    public function __construct(
        private readonly GoogleAdsMetricQueries $metrics,
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsCampaignReader $campaigns,
    ) {
    }

    /** Month-to-date pacing vs the Business monthly target. */
    public function pacing(GoogleAdsAccount $account, ?CarbonImmutable $now = null): GoogleAdsPacing
    {
        return $this->pacingFor($account, GoogleAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account, $now));
    }

    public function read(GoogleAdsAccount $account, ?GoogleAdsPeriod $cplPeriod = null, ?CarbonImmutable $now = null): GoogleAdsBudgetOverview
    {
        $month = GoogleAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account, $now);
        $cplPeriod ??= GoogleAdsPeriod::resolve(GoogleAdsPeriod::DEFAULT, $account, $now);

        $currentCpl = $this->metrics
            ->totals($account, GoogleAdsMetricLevel::Campaign, $cplPeriod->fromDate(), $cplPeriod->toDate())
            ->cplMicros();
        $target = $account->target_cpl_micros === null ? null : (int) $account->target_cpl_micros;

        return new GoogleAdsBudgetOverview(
            currencyCode: (string) $account->currency_code,
            monthPeriod: $month,
            pacing: $this->pacingFor($account, $month),
            targetCplMicros: $target,
            cplPeriod: $cplPeriod,
            currentCplMicros: $currentCpl,
            cplStatus: $this->calculator()->cplStatus($currentCpl, $target),
            campaigns: $this->campaigns->list($account, $month),
        );
    }

    public function cplStatus(?int $currentCplMicros, ?int $targetCplMicros): ?GoogleAdsCplStatus
    {
        return $this->calculator()->cplStatus($currentCplMicros, $targetCplMicros);
    }

    private function pacingFor(GoogleAdsAccount $account, GoogleAdsPeriod $month): GoogleAdsPacing
    {
        $totals = $this->metrics->totals($account, GoogleAdsMetricLevel::Campaign, $month->fromDate(), $month->toDate());

        return $this->calculator()->pacing(
            spentMicros: $totals->spendMicros,
            targetMicros: $account->monthly_budget_target_micros === null ? null : (int) $account->monthly_budget_target_micros,
            daysElapsed: $totals->lastDate === null ? 0 : CarbonImmutable::parse($totals->lastDate)->day,
            daysInMonth: $month->from->daysInMonth,
            daysWithData: $totals->dayCount,
        );
    }

    private function calculator(): GoogleAdsPacingCalculator
    {
        return $this->calculator ??= GoogleAdsPacingCalculator::fromConfig($this->config);
    }
}
