<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;

/**
 * One search term over a period, per (term, campaign, ad group) — the unit an
 * owner can act on, because a negative is added to a campaign or ad group.
 *
 * `alreadyNegative` is true when a synced, enabled negative keyword with the
 * exact same text (case-insensitive) already applies at the term's campaign
 * or ad-group level, so the UI can say "Already excluded".
 * `internal` holds LOCAL row ids for the owner-confirmed action flow; they
 * are not Google ids but still never belong in a rendered page.
 */
final class GoogleAdsSearchTermRow
{
    /** @param  array{campaign_id: int, ad_group_id: int, search_term_id: int}  $internal */
    public function __construct(
        public readonly string $term,
        public readonly string $termHash,
        public readonly string $campaignUid,
        public readonly string $campaignName,
        public readonly string $adGroupName,
        public readonly ?string $matchedKeywordText,
        public readonly ?GoogleAdsMatchType $matchedKeywordMatchType,
        public readonly ?GoogleAdsSearchTermStatus $targetingStatus,
        public readonly GoogleAdsSearchTermReviewState $reviewState,
        public readonly GoogleAdsSearchTermClass $classification,
        public readonly bool $alreadyNegative,
        public readonly GoogleAdsMetricTotals $totals,
        public readonly array $internal,
    ) {
    }

    public function cplMicros(): ?int
    {
        return $this->totals->cplMicros();
    }

    public function conversionRate(): ?float
    {
        return $this->totals->conversionRate();
    }
}
