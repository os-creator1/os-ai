<?php

namespace App\Library\GoogleAds\Recommendations;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Library\GoogleAds\Reporting\GoogleAdsBudgetReader;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignRow;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReader;
use App\Models\GoogleAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 contract §12 — deterministic recommendation FACTS.
 * Read-only, cache-only, nothing stored, no lifecycle, no AI, no provider
 * call. Every threshold comes from GoogleAdsConfig (config/google_ads.php).
 *
 *   wasted_search_terms       potential-waste search terms (same classifier as
 *                             the Search terms page) not already covered by
 *                             an enabled negative; one account-level fact
 *   zero_conversion_campaign  ENABLED campaign, spend >= zero_conv_campaign_min_spend
 *                             and conversions PRESENT and = 0
 *   cpl_above_target          target CPL set, CPL > target x cpl_over_factor
 *   strong_campaign           target CPL set, CPL <= target and
 *                             conversions >= strong_min_conversions
 *   pacing_over / pacing_under  month-to-date pacing status ahead / behind
 *                             with at least pacing.min_days days of data
 *
 * INSUFFICIENT EVIDENCE => NO FACT: absent conversion data, no target (CPL
 * rules), fewer than min days (pacing) and sub-threshold spend all produce
 * nothing, never a guess. Order is deterministic: spend descending, then
 * type, then subject uid.
 */
final class GoogleAdsRecommendationFactReader
{
    private const CAMPAIGN_LIMIT = 500;

    public function __construct(
        private readonly GoogleAdsCampaignReader $campaigns,
        private readonly GoogleAdsSearchTermReader $searchTerms,
        private readonly GoogleAdsBudgetReader $budget,
        private readonly GoogleAdsConfig $config,
    ) {
    }

    /** @return array<int, GoogleAdsRecommendationFact> */
    public function facts(GoogleAdsAccount $account, ?GoogleAdsPeriod $period = null, ?CarbonImmutable $now = null): array
    {
        $period ??= GoogleAdsPeriod::resolve(null, $account, $now);
        $currency = (string) $account->currency_code;

        $facts = array_merge(
            $this->wasteFacts($account, $period, $currency),
            $this->campaignFacts($account, $period, $currency),
            $this->pacingFacts($account, $currency, $now),
        );

        usort($facts, static fn (GoogleAdsRecommendationFact $a, GoogleAdsRecommendationFact $b): int => [$b->rankSpendMicros, $a->type->value, (string) $a->subjectUid]
            <=> [$a->rankSpendMicros, $b->type->value, (string) $b->subjectUid]);

        return $facts;
    }

    /** @return array<int, GoogleAdsRecommendationFact> */
    private function wasteFacts(GoogleAdsAccount $account, GoogleAdsPeriod $period, string $currency): array
    {
        $summary = $this->searchTerms->wasteSummary($account, $period);

        if (! $summary->hasData || ($summary->termCount ?? 0) < 1) {
            return [];
        }

        $top = $summary->topTerms[0] ?? null;

        return [new GoogleAdsRecommendationFact(
            type: GoogleAdsRecommendationType::WastedSearchTerms,
            subjectType: 'account',
            subjectUid: null,
            subjectName: null,
            evidence: $this->periodEvidence($period, (int) $summary->spendMicros) + [
                'term_count' => $summary->termCount,
                'wasted_spend_micros' => $summary->spendMicros,
                'wasted_clicks' => $summary->clicks,
                'top_term' => $top?->term,
                'top_term_spend_micros' => $top?->totals->spendMicros,
                'top_term_clicks' => $top?->totals->clicks,
                'already_excluded_count' => $summary->alreadyExcludedCount,
            ],
            suggestedAction: ['key' => 'review_search_terms', 'target_uid' => null],
            factualBasis: 'rule:wasted_search_terms_min_spend',
            rankSpendMicros: (int) $summary->spendMicros,
            currencyCode: $currency,
        )];
    }

    /** @return array<int, GoogleAdsRecommendationFact> */
    private function campaignFacts(GoogleAdsAccount $account, GoogleAdsPeriod $period, string $currency): array
    {
        $target = $account->target_cpl_micros === null ? null : (int) $account->target_cpl_micros;
        $facts = [];

        foreach ($this->campaigns->list($account, $period, GoogleAdsEntityStatus::Enabled->value, self::CAMPAIGN_LIMIT) as $campaign) {
            $totals = $campaign->totals;

            if (! $totals->hasData() || $totals->spendMicros === null || $totals->conversions === null) {
                continue; // no spend or no conversion data: insufficient evidence
            }

            $spend = $totals->spendMicros;
            $cpl = $totals->cplMicros();

            if (bccomp($totals->conversions, '0', 6) === 0) {
                if ($spend >= $this->config->zeroConversionCampaignMinSpendMicros()) {
                    $facts[] = $this->campaignFact(
                        GoogleAdsRecommendationType::ZeroConversionCampaign,
                        'rule:zero_conversions_min_spend',
                        'review_campaign',
                        $campaign,
                        $period,
                        $currency,
                        ['clicks' => $totals->clicks, 'impressions' => $totals->impressions, 'conversions' => $totals->conversionsDisplay()],
                    );
                }

                continue;
            }

            if ($target === null || $target <= 0 || $cpl === null) {
                continue; // CPL rules need a target and conversions
            }

            $factor = number_format($this->config->cplOverFactor(), 6, '.', '');

            if (bccomp((string) $cpl, bcmul((string) $target, $factor, 6), 6) > 0) {
                $facts[] = $this->campaignFact(
                    GoogleAdsRecommendationType::CplAboveTarget,
                    'rule:cpl_over_target_factor',
                    'review_campaign',
                    $campaign,
                    $period,
                    $currency,
                    ['conversions' => $totals->conversionsDisplay(), 'cpl_micros' => $cpl, 'target_cpl_micros' => $target, 'threshold_factor' => (float) $factor],
                );
            } elseif ($cpl <= $target && bccomp($totals->conversions, (string) $this->config->strongMinConversions(), 6) >= 0) {
                $facts[] = $this->campaignFact(
                    GoogleAdsRecommendationType::StrongCampaign,
                    'rule:cpl_at_or_below_target_min_conversions',
                    'review_campaign',
                    $campaign,
                    $period,
                    $currency,
                    ['conversions' => $totals->conversionsDisplay(), 'cpl_micros' => $cpl, 'target_cpl_micros' => $target],
                );
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function campaignFact(
        GoogleAdsRecommendationType $type,
        string $basis,
        string $action,
        GoogleAdsCampaignRow $campaign,
        GoogleAdsPeriod $period,
        string $currency,
        array $extra,
    ): GoogleAdsRecommendationFact {
        $spend = (int) $campaign->totals->spendMicros;

        return new GoogleAdsRecommendationFact(
            type: $type,
            subjectType: 'campaign',
            subjectUid: $campaign->uid,
            subjectName: $campaign->name,
            evidence: $this->periodEvidence($period, $spend) + $extra,
            suggestedAction: ['key' => $action, 'target_uid' => $campaign->uid],
            factualBasis: $basis,
            rankSpendMicros: $spend,
            currencyCode: $currency,
        );
    }

    /** @return array<int, GoogleAdsRecommendationFact> */
    private function pacingFacts(GoogleAdsAccount $account, string $currency, ?CarbonImmutable $now): array
    {
        $pacing = $this->budget->pacing($account, $now);

        $type = match ($pacing->status) {
            GoogleAdsPacingStatus::Ahead => GoogleAdsRecommendationType::PacingOver,
            GoogleAdsPacingStatus::Behind => GoogleAdsRecommendationType::PacingUnder,
            default => null,
        };

        if ($type === null || $pacing->spentMicros === null) {
            return [];
        }

        $month = GoogleAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account, $now);

        return [new GoogleAdsRecommendationFact(
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
            factualBasis: $type === GoogleAdsRecommendationType::PacingOver ? 'rule:pacing_ahead_of_target' : 'rule:pacing_behind_target',
            rankSpendMicros: $pacing->spentMicros,
            currencyCode: $currency,
        )];
    }

    /** @return array<string, scalar|null> */
    private function periodEvidence(GoogleAdsPeriod $period, int $spendMicros): array
    {
        return [
            'period_key' => $period->key,
            'period_from' => $period->fromDate(),
            'period_to' => $period->toDate(),
            'spend_micros' => $spendMicros,
        ];
    }
}
