<?php

namespace App\DTO\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §2/§4 — one per-day search-term row.
 *
 * `matchedKeywordText` / `matchedKeywordMatchType` are the OPTIONAL keyword
 * association (§2: tolerated as absent). The real client does not select the
 * unverified `segments.keyword.info.*` fields, so they are null from Google
 * today; a fixture or a later verified query may populate them.
 */
final readonly class GoogleAdsSearchTermData
{
    public function __construct(
        public string $externalCampaignId,
        public string $externalAdGroupId,
        public string $searchTerm,
        public string $date,
        public GoogleAdsMetrics $metrics,
        public GoogleAdsSearchTermStatus $targetingStatus,
        public ?string $matchedKeywordText = null,
        public ?GoogleAdsMatchType $matchedKeywordMatchType = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row  FROM search_term_view
     */
    public static function fromSearchRow(array $row): ?self
    {
        $campaignId = J::id(J::dig($row, ['campaign', 'id']));
        $adGroupId = J::id(J::dig($row, ['adGroup', 'id']));
        $term = J::string(J::dig($row, ['searchTermView', 'searchTerm']), 255);
        $date = J::date(J::dig($row, ['segments', 'date']));

        if ($campaignId === null || $adGroupId === null || $term === null || $date === null) {
            return null;
        }

        return new self(
            externalCampaignId: $campaignId,
            externalAdGroupId: $adGroupId,
            searchTerm: $term,
            date: $date,
            metrics: GoogleAdsMetrics::fromProvider($row['metrics'] ?? null),
            targetingStatus: GoogleAdsSearchTermStatus::fromProvider(J::dig($row, ['searchTermView', 'status'])),
        );
    }
}
