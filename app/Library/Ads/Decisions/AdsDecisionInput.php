<?php

namespace App\Library\Ads\Decisions;

use App\Library\Acquisition\Economics\EconomicsProfile;

/**
 * Everything the decision engine is allowed to know about ONE provider's
 * delivery for ONE Acquisition Purpose over a period. A plain value object so
 * the engine is pure and every rule can be tested by constructing one.
 *
 * Absence is `null`, never 0: `providerResults`, `impressions`, `clicks` and
 * `searchTermWasteMicros` are null when the provider did not report them (a
 * Meta account with no chosen result type; Meta, which has no search terms).
 *
 * `qualified` counts Opportunities in the purpose's pipeline, attributed to
 * this provider, that are won or have left the first stage; `outcomes` counts
 * the won ones; `inquiries` counts every attributed Opportunity.
 * `attributedTouches` is the number of this provider's attributed first
 * touches the Business has recorded recently across ALL goals — the tracking
 * probe.
 */
final class AdsDecisionInput
{
    /**
     * @param  array<string, string>  $labels  the purpose's wording: person, lead, leads, outcome, outcomes, cost_per_lead, cost_per_outcome, pipeline_cta
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $purposeName,
        public readonly string $outcomeType,
        public readonly array $labels,
        public readonly bool $pipelineLinked,
        public readonly ?EconomicsProfile $economics,
        public readonly string $currency,
        public readonly bool $currencyMatchesBusiness,
        public readonly int $spendMicros,
        public readonly ?int $impressions,
        public readonly ?int $clicks,
        public readonly ?float $providerResults,
        public readonly int $inquiries,
        public readonly int $qualified,
        public readonly int $outcomes,
        public readonly int $attributedTouches,
        public readonly ?int $searchTermWasteMicros = null,
        public readonly ?string $focusCampaignUid = null,
        public readonly ?string $purposeUid = null,
        public readonly string $businessCurrency = '',
        public readonly ?int $milestone = null,
    ) {
    }

    public function label(string $key, string $default = ''): string
    {
        $value = $this->labels[$key] ?? '';

        return $value !== '' ? $value : $default;
    }

    public function isGoogle(): bool
    {
        return $this->provider === 'google';
    }

    public function providerName(): string
    {
        return $this->isGoogle() ? 'Google' : 'Meta';
    }
}
