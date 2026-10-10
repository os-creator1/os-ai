<?php

namespace App\Library\Ads\Decisions;

/**
 * Typed access to config/ads_decisions.php — the ONLY place the decision
 * engine gets a threshold from. Constructible from an array so the tests can
 * pin every default and exercise alternatives without touching config.
 */
final class AdsDecisionPolicy
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('ads_decisions.decision', []));
    }

    public function zeroResultWatchFromMultiple(): float
    {
        return (float) ($this->values['zero_result_watch_from_multiple'] ?? 1.0);
    }

    public function zeroResultActAtMultiple(): float
    {
        return (float) ($this->values['zero_result_act_at_multiple'] ?? 2.5);
    }

    public function minQualifiedForCostJudgement(): int
    {
        return (int) ($this->values['min_qualified_for_cost_judgement'] ?? 10);
    }

    public function overTargetToleranceMultiple(): float
    {
        return (float) ($this->values['over_target_tolerance_multiple'] ?? 1.25);
    }

    public function minQualifiedForFunnelJudgement(): int
    {
        return (int) ($this->values['min_qualified_for_funnel_judgement'] ?? 10);
    }

    public function minOutcomesForCac(): int
    {
        return (int) ($this->values['min_outcomes_for_cac'] ?? 2);
    }

    public function weakConversionShareOfExpected(): float
    {
        return (float) ($this->values['weak_conversion_share_of_expected'] ?? 0.5);
    }

    public function minClicksForTrackingCheck(): int
    {
        return (int) ($this->values['min_clicks_for_tracking_check'] ?? 30);
    }

    public function weakCtr(): float
    {
        return (float) ($this->values['weak_ctr'] ?? 0.005);
    }

    public function minImpressionsForCtr(): int
    {
        return (int) ($this->values['min_impressions_for_ctr'] ?? 2000);
    }

    public function weakClickToInquiry(): float
    {
        return (float) ($this->values['weak_click_to_inquiry'] ?? 0.01);
    }

    public function reviewAfterMoreQualified(): int
    {
        return (int) ($this->values['review_after_more_qualified'] ?? 4);
    }

    public function reviewAfterMoreSpendMultiple(): float
    {
        return (float) ($this->values['review_after_more_spend_multiple'] ?? 1.0);
    }

    public function searchTermWasteShare(): float
    {
        return (float) ($this->values['search_term_waste_share'] ?? 0.25);
    }

    public function searchTermWasteMinTargetMultiple(): float
    {
        return (float) ($this->values['search_term_waste_min_target_multiple'] ?? 1.0);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
