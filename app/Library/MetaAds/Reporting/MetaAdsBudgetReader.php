<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPacing;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingCalculator;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsConfig;
use App\Models\MetaAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract 24 §5.3 — reads the cached month-to-date facts
 * and hands them to the provider-neutral GoogleAdsPacingCalculator (reused by
 * composition; min days 7, tolerance 15% from MetaAdsConfig). Pacing is always
 * about the CURRENT calendar month in the account time zone, whatever period
 * the page shows. Campaign-level rows only. The targets are Meta's own
 * (meta_ads_accounts.monthly_budget_target_micros / target_cost_per_result_micros)
 * and are never derived from, or added to, Google's.
 */
final class MetaAdsBudgetReader
{
    private ?GoogleAdsPacingCalculator $calculator = null;

    public function __construct(
        private readonly MetaAdsMetricQueries $metrics,
        private readonly MetaAdsConfig $config,
        private readonly MetaAdsCampaignReader $campaigns,
    ) {
    }

    /** Month-to-date pacing vs the Meta monthly target. */
    public function pacing(MetaAdsAccount $account, ?CarbonImmutable $now = null): GoogleAdsPacing
    {
        return $this->pacingFor($account, MetaAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account, $now));
    }

    public function read(MetaAdsAccount $account, ?GoogleAdsPeriod $costPerResultPeriod = null, ?CarbonImmutable $now = null): MetaAdsBudgetOverview
    {
        $month = MetaAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account, $now);
        $costPerResultPeriod ??= MetaAdsPeriod::resolve(GoogleAdsPeriod::DEFAULT, $account, $now);

        $totals = $this->metrics->totals($account, MetaAdsLevel::Campaign, $costPerResultPeriod->fromDate(), $costPerResultPeriod->toDate());
        $current = $totals->costPerResultMicros();
        $target = $this->target($account);

        return new MetaAdsBudgetOverview(
            currencyCode: (string) $account->currency_code,
            monthPeriod: $month,
            pacing: $this->pacingFor($account, $month),
            targetCostPerResultMicros: $target,
            costPerResultPeriod: $costPerResultPeriod,
            resultTypeChosen: $totals->resultTypeChosen,
            currentCostPerResultMicros: $current,
            costPerResultStatus: $this->calculator()->cplStatus($current, $target),
            campaigns: $this->campaigns->list($account, $month),
        );
    }

    public function costPerResultStatus(?int $currentMicros, ?int $targetMicros): ?GoogleAdsCplStatus
    {
        return $this->calculator()->cplStatus($currentMicros, $targetMicros);
    }

    public function target(MetaAdsAccount $account): ?int
    {
        return $account->target_cost_per_result_micros === null ? null : (int) $account->target_cost_per_result_micros;
    }

    private function pacingFor(MetaAdsAccount $account, GoogleAdsPeriod $month): GoogleAdsPacing
    {
        $totals = $this->metrics->totals($account, MetaAdsLevel::Campaign, $month->fromDate(), $month->toDate());

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
        return $this->calculator ??= new GoogleAdsPacingCalculator($this->config->pacingTolerance(), $this->config->pacingMinDays());
    }
}
