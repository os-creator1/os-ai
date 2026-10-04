<?php

namespace App\Library\GoogleAds\Recommendations;

/**
 * Google Ads Module V1 contract §12 — one deterministic recommendation FACT.
 * Structured data only (no wording, nothing stored, no lifecycle).
 *
 * EVIDENCE KEYS (flat, scalar|null; they map 1:1 onto the future RFC-002
 * Opportunity `allowed_evidence_fact_keys` of the matching `ads_*` type):
 *
 *   all types            period_key, period_from, period_to, spend_micros
 *   wasted_search_terms  term_count, wasted_spend_micros, wasted_clicks,
 *                        top_term, top_term_spend_micros, top_term_clicks
 *                        (+ already_excluded_count: terms an enabled negative
 *                        already covers, NOT counted above)
 *   zero_conversion_campaign
 *                        clicks, impressions, conversions ("0")
 *   cpl_above_target     conversions, cpl_micros, target_cpl_micros,
 *                        threshold_factor
 *   strong_campaign      conversions, cpl_micros, target_cpl_micros
 *   pacing_over / pacing_under
 *                        monthly_target_micros, projected_micros,
 *                        projection_low_confidence, days_with_data,
 *                        days_elapsed, days_in_month,
 *                        elapsed_proportion, spend_proportion
 *
 * Money keys end in `_micros` (account currency, see `currencyCode`).
 * `factualBasis` names the deterministic rule that produced the fact, e.g.
 * 'rule:zero_conversions_min_spend'. `suggestedAction` is
 * {key, target_uid}: a descriptor of an existing owner-confirmed flow, never
 * something executed here. `rankSpendMicros` is the spend the fact is about
 * (facts are ordered by it, descending).
 *
 * `subjectUid` is the campaign uid (campaign facts) or null (account-level
 * facts); Google's own ids never appear.
 */
final class GoogleAdsRecommendationFact
{
    /**
     * @param  array<string, scalar|null>  $evidence
     * @param  array{key: string, target_uid: ?string}  $suggestedAction
     */
    public function __construct(
        public readonly GoogleAdsRecommendationType $type,
        /** 'account' | 'campaign' */
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'subject' => ['type' => $this->subjectType, 'uid' => $this->subjectUid, 'name' => $this->subjectName],
            'evidence' => $this->evidence,
            'suggested_action' => $this->suggestedAction,
            'factual_basis' => $this->factualBasis,
            'currency_code' => $this->currencyCode,
        ];
    }
}
