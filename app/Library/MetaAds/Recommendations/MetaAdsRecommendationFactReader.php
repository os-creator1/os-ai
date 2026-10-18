<?php

namespace App\Library\MetaAds\Recommendations;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\Reporting\MetaAdsBudgetReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignRow;
use App\Library\MetaAds\Reporting\MetaAdsMetricQueries;
use App\Library\MetaAds\Reporting\MetaAdsMetricTotals;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract 24 §12 — deterministic recommendation FACTS.
 * Read-only, cache-only, nothing stored, no lifecycle, no AI, no provider
 * call, no causation wording. Every threshold comes from MetaAdsConfig
 * (config/meta_ads.php).
 *
 *   zero_result_spend            ACTIVE campaign, spend >= zero_result_min_spend_micros
 *                                and chosen-type results PRESENT and = 0
 *   cost_per_result_above_target target set, results > 0, cost per result > target x cpr_over_factor
 *   strong_performer             target set, cost per result <= target and results >= strong_min_results
 *   pacing_over / pacing_under   month-to-date pacing ahead / behind with >= pacing.min_days days of data
 *   high_frequency_weak_results  ACTIVE ad set with frequency_7d >= frequency_threshold AND, over
 *                                ad-set-level rows, the last 7 days (ending on the account-local
 *                                "today") against the 7 days before:
 *                                  - baseline (previous 7 days) has >= fatigue_min_results results, and
 *                                  - last-7-day spend >= fatigue_min_spend_micros, and either
 *                                  (a) last 7 days also has >= fatigue_min_results results and cost per
 *                                      result >= fatigue_cpr_worsening_factor x the previous 7 days
 *                                      (exact cross-multiplication, edge inclusive), or
 *                                  (b) last 7 days has 0 results (results stopped).
 *                                A last window with 1..min-1 results is insufficient evidence: no fact.
 *   delivery_issue               status ACTIVE but Meta-reported effective_status in
 *                                WITH_ISSUES / DISAPPROVED / PENDING_BILLING_INFO on a campaign,
 *                                ad set or ad (quoted, never inferred)
 *
 * Result-based rules need a CHOSEN result type and result data; without one
 * they produce nothing (never a guess). Order is deterministic: spend
 * descending, then type, then subject uid.
 *
 * Queries: campaign list (3) + pacing (2-3) + fatigue (<= 3) + delivery
 * (<= 3 entity + 3 spend); independent of campaign / ad set / ad counts.
 */
final class MetaAdsRecommendationFactReader
{
    private const CAMPAIGN_LIMIT = 500;

    private const ENTITY_LIMIT = 200;

    public const DELIVERY_ISSUE_STATUSES = ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_BILLING_INFO'];

    public function __construct(
        private readonly MetaAdsCampaignReader $campaigns,
        private readonly MetaAdsBudgetReader $budget,
        private readonly MetaAdsMetricQueries $metrics,
        private readonly MetaAdsConfig $config,
    ) {
    }

    /** @return array<int, MetaAdsRecommendationFact> */
    public function facts(MetaAdsAccount $account, ?GoogleAdsPeriod $period = null, ?CarbonImmutable $now = null): array
    {
        $period ??= MetaAdsPeriod::resolve(null, $account, $now);
        $currency = (string) $account->currency_code;
        $type = $this->metrics->results()->chosenType($account);
        $label = $this->metrics->results()->chosenLabel($account);

        $facts = array_merge(
            $type === null ? [] : $this->campaignFacts($account, $period, $currency, (string) $label),
            $this->pacingFacts($account, $currency, $now),
            $type === null ? [] : $this->fatigueFacts($account, $period, $currency, $type, (string) $label),
            $this->deliveryFacts($account, $period, $currency),
        );

        usort($facts, static fn (MetaAdsRecommendationFact $a, MetaAdsRecommendationFact $b): int => [$b->rankSpendMicros, $a->type->value, (string) $a->subjectUid]
            <=> [$a->rankSpendMicros, $b->type->value, (string) $b->subjectUid]);

        return $facts;
    }

    /**
     * Growth-facing (contract §13): the provider-aware identities of the
     * current facts, {provider, type, subject_type, subject_uid, period_key}.
     * Growth consumes facts and keys its Opportunities on these; it never
     * recomputes metrics or calls Meta.
     *
     * @return array<int, array{provider: string, type: string, subject_type: string, subject_uid: ?string, period_key: string}>
     */
    public function providerFactIds(MetaAdsAccount $account, ?GoogleAdsPeriod $period = null, ?CarbonImmutable $now = null): array
    {
        return array_map(
            static fn (MetaAdsRecommendationFact $fact): array => $fact->identity(),
            $this->facts($account, $period, $now),
        );
    }

    /** @return array<int, MetaAdsRecommendationFact> */
    private function campaignFacts(MetaAdsAccount $account, GoogleAdsPeriod $period, string $currency, string $label): array
    {
        $target = $this->budget->target($account);
        $facts = [];

        foreach ($this->campaigns->list($account, $period, 'ACTIVE', self::CAMPAIGN_LIMIT) as $campaign) {
            $totals = $campaign->totals;

            if (! $totals->hasData() || $totals->spendMicros === null || $totals->results === null) {
                continue; // no spend or no result data: insufficient evidence
            }

            $spend = $totals->spendMicros;
            $cpr = $totals->costPerResultMicros();

            if (bccomp($totals->results, '0', 6) === 0) {
                if ($spend >= $this->config->zeroResultMinSpendMicros()) {
                    $facts[] = $this->campaignFact(
                        MetaAdsRecommendationType::ZeroResultSpend,
                        'rule:zero_results_min_spend',
                        $campaign,
                        $period,
                        $currency,
                        ['result_label' => $label, 'clicks' => $totals->clicks, 'link_clicks' => $totals->linkClicks, 'impressions' => $totals->impressions, 'results' => '0'],
                    );
                }

                continue;
            }

            if ($target === null || $target <= 0 || $cpr === null) {
                continue; // cost-per-result rules need a target and results
            }

            $factor = number_format($this->config->cprOverFactor(), 6, '.', '');

            if (bccomp((string) $cpr, bcmul((string) $target, $factor, 6), 6) > 0) {
                $facts[] = $this->campaignFact(
                    MetaAdsRecommendationType::CostPerResultAboveTarget,
                    'rule:cost_per_result_over_target_factor',
                    $campaign,
                    $period,
                    $currency,
                    ['result_label' => $label, 'results' => $totals->resultsDisplay(), 'cost_per_result_micros' => $cpr, 'target_cost_per_result_micros' => $target, 'threshold_factor' => (float) $factor],
                );
            } elseif ($cpr <= $target && bccomp($totals->results, (string) $this->config->strongMinResults(), 6) >= 0) {
                $facts[] = $this->campaignFact(
                    MetaAdsRecommendationType::StrongPerformer,
                    'rule:cost_per_result_at_or_below_target_min_results',
                    $campaign,
                    $period,
                    $currency,
                    ['result_label' => $label, 'results' => $totals->resultsDisplay(), 'cost_per_result_micros' => $cpr, 'target_cost_per_result_micros' => $target],
                );
            }
        }

        return $facts;
    }

    /** @param  array<string, scalar|null>  $extra */
    private function campaignFact(
        MetaAdsRecommendationType $type,
        string $basis,
        MetaAdsCampaignRow $campaign,
        GoogleAdsPeriod $period,
        string $currency,
        array $extra,
    ): MetaAdsRecommendationFact {
        $spend = (int) $campaign->totals->spendMicros;

        return new MetaAdsRecommendationFact(
            type: $type,
            subjectType: 'campaign',
            subjectUid: $campaign->uid,
            subjectName: $campaign->name,
            evidence: $this->periodEvidence($period, $spend) + $extra,
            suggestedAction: ['key' => 'review_campaign', 'target_uid' => $campaign->uid],
            factualBasis: $basis,
            rankSpendMicros: $spend,
            currencyCode: $currency,
        );
    }

    /** @return array<int, MetaAdsRecommendationFact> */
    private function pacingFacts(MetaAdsAccount $account, string $currency, ?CarbonImmutable $now): array
    {
        $pacing = $this->budget->pacing($account, $now);

        $type = match ($pacing->status) {
            GoogleAdsPacingStatus::Ahead => MetaAdsRecommendationType::PacingOver,
            GoogleAdsPacingStatus::Behind => MetaAdsRecommendationType::PacingUnder,
            default => null,
        };

        if ($type === null || $pacing->spentMicros === null) {
            return [];
        }

        $month = MetaAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account, $now);

        return [new MetaAdsRecommendationFact(
            type: $type,
            subjectType: 'account',
            subjectUid: null,
            subjectName: null,
            evidence: $this->periodEvidence($month, $pacing->spentMicros) + [
                'monthly_target_micros' => $pacing->monthlyTargetMicros,
                'projected_micros' => $pacing->projectedMicros,
                'projection_low_confidence' => $pacing->projectionLowConfidence,
                'days_with_data' => $pacing->daysWithData,
                'days_elapsed' => $pacing->daysElapsed,
                'days_in_month' => $pacing->daysInMonth,
                'elapsed_proportion' => $pacing->elapsedProportion,
                'spend_proportion' => $pacing->spendProportion,
            ],
            suggestedAction: ['key' => 'review_budget', 'target_uid' => null],
            factualBasis: $type === MetaAdsRecommendationType::PacingOver ? 'rule:pacing_ahead_of_target' : 'rule:pacing_behind_target',
            rankSpendMicros: $pacing->spentMicros,
            currencyCode: $currency,
        )];
    }

    /** @return array<int, MetaAdsRecommendationFact> */
    private function fatigueFacts(MetaAdsAccount $account, GoogleAdsPeriod $period, string $currency, string $type, string $label): array
    {
        $threshold = number_format($this->config->frequencyThreshold(), 4, '.', '');

        $candidates = MetaAdsAdSet::query()
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->where('status', 'ACTIVE')
            ->whereNotNull('frequency_7d')
            ->where('frequency_7d', '>=', $threshold)
            ->orderBy('id')
            ->limit(self::ENTITY_LIMIT)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $end = $period->today;
        $lastFrom = $end->subDays(6)->format('Y-m-d');
        $prevFrom = $end->subDays(13)->format('Y-m-d');
        $prevTo = $end->subDays(7)->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $ids = $candidates->pluck('id')->all();

        $spend = $this->metrics->daily($account, MetaAdsLevel::AdSet, $prevFrom, $endDate)
            ->whereIn('entity_id', $ids)
            ->selectRaw('entity_id, SUM(CASE WHEN metric_date >= ? THEN spend_micros END) AS last_spend, '
                . 'SUM(CASE WHEN metric_date <= ? THEN spend_micros END) AS prev_spend', [$lastFrom, $prevTo])
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');

        $results = $this->metrics->results()->daily($account, MetaAdsLevel::AdSet, $prevFrom, $endDate, $type)
            ->whereIn('entity_id', $ids)
            ->selectRaw('entity_id, SUM(CASE WHEN metric_date >= ? THEN results END) AS last_results, '
                . 'SUM(CASE WHEN metric_date <= ? THEN results END) AS prev_results', [$lastFrom, $prevTo])
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');

        $factor = number_format($this->config->fatigueCprWorseningFactor(), 6, '.', '');
        $minResults = (string) $this->config->fatigueMinResults();
        $facts = [];

        foreach ($candidates as $adSet) {
            $s = $spend->get($adSet->id);
            $r = $results->get($adSet->id);
            $lastSpend = $s === null || $s->last_spend === null ? 0 : (int) $s->last_spend;
            $prevSpend = $s === null || $s->prev_spend === null ? 0 : (int) $s->prev_spend;
            $lastResults = $r === null || $r->last_results === null ? '0.000000' : bcadd((string) $r->last_results, '0', 6);
            $prevResults = $r === null || $r->prev_results === null ? '0.000000' : bcadd((string) $r->prev_results, '0', 6);

            if ($prevSpend <= 0
                || bccomp($prevResults, $minResults, 6) < 0
                || $lastSpend < $this->config->fatigueMinSpendMicros()) {
                continue; // insufficient baseline or spend
            }

            $variant = null;

            if (bccomp($lastResults, '0', 6) === 0) {
                $variant = 'results_stopped';
            } elseif (bccomp($lastResults, $minResults, 6) >= 0) {
                // cost per result (last) >= factor x cost per result (previous), exact:
                // lastSpend / lastResults >= factor x prevSpend / prevResults
                $left = bcmul((string) $lastSpend, $prevResults, 10);
                $right = bcmul(bcmul($factor, (string) $prevSpend, 10), $lastResults, 10);

                if (bccomp($left, $right, 10) >= 0) {
                    $variant = 'cost_per_result_worsened';
                }
            }

            if ($variant === null) {
                continue;
            }

            $lastTotals = new MetaAdsMetricTotals(1, 1, $lastSpend, null, null, null, $lastResults, null, null, true);
            $prevTotals = new MetaAdsMetricTotals(1, 1, $prevSpend, null, null, null, $prevResults, null, null, true);

            $facts[] = new MetaAdsRecommendationFact(
                type: MetaAdsRecommendationType::HighFrequencyWeakResults,
                subjectType: 'ad_set',
                subjectUid: (string) $adSet->uid,
                subjectName: (string) $adSet->name,
                evidence: [
                    'period_key' => GoogleAdsPeriod::LAST_7,
                    'period_from' => $lastFrom,
                    'period_to' => $endDate,
                    'spend_micros' => $lastSpend,
                    'result_label' => $label,
                    'variant' => $variant,
                    'frequency_7d' => (float) $adSet->frequency_7d,
                    'frequency_threshold' => (float) $threshold,
                    'reach_7d' => $adSet->reach_7d === null ? null : (int) $adSet->reach_7d,
                    'last_results' => $lastTotals->resultsDisplay(),
                    'last_cost_per_result_micros' => $lastTotals->costPerResultMicros(),
                    'previous_period_from' => $prevFrom,
                    'previous_period_to' => $prevTo,
                    'previous_spend_micros' => $prevSpend,
                    'previous_results' => $prevTotals->resultsDisplay(),
                    'previous_cost_per_result_micros' => $prevTotals->costPerResultMicros(),
                    'worsening_factor' => (float) $factor,
                ],
                suggestedAction: ['key' => 'review_ad_set', 'target_uid' => (string) $adSet->uid],
                factualBasis: 'rule:frequency_and_cost_per_result_worsening',
                rankSpendMicros: $lastSpend,
                currencyCode: $currency,
            );
        }

        return $facts;
    }

    /** @return array<int, MetaAdsRecommendationFact> */
    private function deliveryFacts(MetaAdsAccount $account, GoogleAdsPeriod $period, string $currency): array
    {
        $sources = [
            ['campaign', MetaAdsLevel::Campaign, MetaAdsCampaign::class, 'review_campaign'],
            ['ad_set', MetaAdsLevel::AdSet, MetaAdsAdSet::class, 'review_ad_set'],
            ['ad', MetaAdsLevel::Ad, MetaAdsAd::class, 'review_ad'],
        ];
        $facts = [];

        foreach ($sources as [$subjectType, $level, $model, $action]) {
            $entities = $model::query()
                ->where('business_id', $account->business_id)
                ->where('meta_ads_account_id', $account->id)
                ->where('status', 'ACTIVE')
                ->whereIn('effective_status', self::DELIVERY_ISSUE_STATUSES)
                ->orderBy('id')
                ->limit(self::ENTITY_LIMIT)
                ->get(['id', 'uid', 'name', 'status', 'effective_status']);

            if ($entities->isEmpty()) {
                continue;
            }

            $spend = $this->metrics->daily($account, $level, $period->fromDate(), $period->toDate())
                ->whereIn('entity_id', $entities->pluck('id')->all())
                ->selectRaw('entity_id, SUM(spend_micros) AS spend_micros')
                ->groupBy('entity_id')
                ->pluck('spend_micros', 'entity_id');

            foreach ($entities as $entity) {
                $entitySpend = $spend->has($entity->id) && $spend->get($entity->id) !== null ? (int) $spend->get($entity->id) : null;

                $facts[] = new MetaAdsRecommendationFact(
                    type: MetaAdsRecommendationType::DeliveryIssue,
                    subjectType: $subjectType,
                    subjectUid: (string) $entity->uid,
                    subjectName: (string) $entity->name,
                    evidence: $this->periodEvidence($period, $entitySpend) + [
                        'entity_type' => $subjectType,
                        'status' => (string) $entity->status,
                        'effective_status' => (string) $entity->effective_status,
                    ],
                    suggestedAction: ['key' => $action, 'target_uid' => (string) $entity->uid],
                    factualBasis: 'rule:active_with_provider_reported_delivery_issue',
                    rankSpendMicros: $entitySpend ?? 0,
                    currencyCode: $currency,
                );
            }
        }

        return $facts;
    }

    /** @return array<string, scalar|null> */
    private function periodEvidence(GoogleAdsPeriod $period, ?int $spendMicros): array
    {
        return [
            'period_key' => $period->key,
            'period_from' => $period->fromDate(),
            'period_to' => $period->toDate(),
            'spend_micros' => $spendMicros,
        ];
    }
}
