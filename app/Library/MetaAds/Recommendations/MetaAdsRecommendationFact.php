<?php

namespace App\Library\MetaAds\Recommendations;

/**
 * Meta Ads Module V1 contract 24 §12 — one deterministic recommendation FACT.
 * Structured data only (no wording, nothing stored, no lifecycle). Mirrors
 * GoogleAdsRecommendationFact::toArray() plus `provider` = 'meta'.
 *
 * EVIDENCE KEYS (flat, scalar|null):
 *
 *   all types              period_key, period_from, period_to, spend_micros
 *   result-based types     result_label (owner-facing label of the chosen result type)
 *   zero_result_spend      clicks, link_clicks, impressions, results ("0")
 *   cost_per_result_above_target
 *                          results, cost_per_result_micros, target_cost_per_result_micros,
 *                          threshold_factor
 *   strong_performer       results, cost_per_result_micros, target_cost_per_result_micros
 *   pacing_over / pacing_under
 *                          monthly_target_micros, projected_micros, projection_low_confidence,
 *                          days_with_data, days_elapsed, days_in_month,
 *                          elapsed_proportion, spend_proportion
 *   high_frequency_weak_results   (period_* = the last 7 days; spend_micros = last-7-day spend)
 *                          variant ('cost_per_result_worsened' | 'results_stopped'),
 *                          frequency_7d, frequency_threshold, reach_7d,
 *                          last_results, last_cost_per_result_micros (null for results_stopped),
 *                          previous_period_from, previous_period_to, previous_spend_micros,
 *                          previous_results, previous_cost_per_result_micros, worsening_factor
 *   delivery_issue         entity_type ('campaign'|'ad_set'|'ad'), status ('ACTIVE'),
 *                          effective_status (as Meta reported it; spend_micros is null when
 *                          the entity has no insight rows in the period)
 *
 * Money keys end in `_micros` (account currency, see `currencyCode`).
 * `factualBasis` names the rule, e.g. 'rule:zero_results_min_spend'.
 * `suggestedAction` is {key, target_uid}: a descriptor only
 * (review_campaign | review_ad_set | review_ad | review_budget). `subjectType`
 * is account | campaign | ad_set | ad; `subjectUid` is the local uid (null for
 * account-level facts). Meta's own ids NEVER appear anywhere in a fact.
 */
final class MetaAdsRecommendationFact
{
    public const PROVIDER = 'meta';

    /**
     * @param  array<string, scalar|null>  $evidence
     * @param  array{key: string, target_uid: ?string}  $suggestedAction
     */
    public function __construct(
        public readonly MetaAdsRecommendationType $type,
        public readonly string $subjectType,
        public readonly ?string $subjectUid,
        public readonly ?string $subjectName,
        public readonly array $evidence,
        public readonly array $suggestedAction,
        public readonly string $factualBasis,
        public readonly int $rankSpendMicros,
        public readonly string $currencyCode,
    ) {
    }

    /**
     * The provider-aware identity a Growth lane keys on (contract §13):
     * {provider, type, subject_type, subject_uid, period_key}. Stable for a
     * given subject + rule + period; contains no provider id.
     *
     * @return array{provider: string, type: string, subject_type: string, subject_uid: ?string, period_key: string}
     */
    public function identity(): array
    {
        return [
            'provider' => self::PROVIDER,
            'type' => $this->type->value,
            'subject_type' => $this->subjectType,
            'subject_uid' => $this->subjectUid,
            'period_key' => (string) ($this->evidence['period_key'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'provider' => self::PROVIDER,
            'subject' => ['type' => $this->subjectType, 'uid' => $this->subjectUid, 'name' => $this->subjectName],
            'evidence' => $this->evidence,
            'suggested_action' => $this->suggestedAction,
            'factual_basis' => $this->factualBasis,
            'currency_code' => $this->currencyCode,
        ];
    }
}
