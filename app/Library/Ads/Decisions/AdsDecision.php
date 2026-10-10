<?php

namespace App\Library\Ads\Decisions;

/**
 * One deterministic verdict for one goal on one provider: what to do, why,
 * what NOT to change, when enough evidence will exist to look again, and the
 * single place to click.
 *
 * `evidence` is the expandable "Why am I seeing this?" list. `claimsProfit` is
 * true ONLY when the owner's own economics and enough downstream outcomes
 * support the word "profitable"; nothing else may print it.
 */
final class AdsDecision
{
    /**
     * @param  list<string>  $reasons  plain sentences, most important first
     * @param  list<array{label: string, value: string}>  $evidence
     * @param  list<string>  $notes  secondary, provider-specific observations that do not change the verdict
     * @param  list<array{label: string, value: string, note: ?string}>  $kpis  the BUSINESS-outcome KPI row; unknown is a dash, never 0
     */
    public function __construct(
        public readonly AdsDecisionState $state,
        public readonly string $purposeName,
        public readonly ?string $purposeUid,
        public readonly string $headline,
        public readonly array $reasons,
        public readonly ?string $doNotChange,
        public readonly ?string $nextReview,
        public readonly ?AdsDecisionCtaKind $ctaKind,
        public readonly ?string $ctaLabel,
        public readonly ?string $focusCampaignUid,
        public readonly array $evidence,
        public readonly array $notes = [],
        public readonly bool $claimsProfit = false,
        public readonly ?string $diagnosis = null,
        public readonly array $kpis = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'purpose' => $this->purposeName,
            'headline' => $this->headline,
            'reasons' => $this->reasons,
            'do_not_change' => $this->doNotChange,
            'next_review' => $this->nextReview,
            'cta' => $this->ctaKind?->value,
            'cta_label' => $this->ctaLabel,
            'evidence' => $this->evidence,
            'notes' => $this->notes,
            'claims_profit' => $this->claimsProfit,
            'diagnosis' => $this->diagnosis,
        ];
    }
}
