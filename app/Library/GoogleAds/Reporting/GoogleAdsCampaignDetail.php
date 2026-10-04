<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * Everything the campaign detail page shows, from cached rows.
 *
 * @property-read array<string, mixed> $trend  {labels, tooltips, series, ...} (see GoogleAdsTrendSeries)
 */
final class GoogleAdsCampaignDetail
{
    /**
     * @param  array<string, mixed>  $trend
     * @param  array<int, array{name: string, status: \App\Enums\GoogleAds\GoogleAdsEntityStatus, totals: GoogleAdsMetricTotals}>  $adGroups
     * @param  GoogleAdsPagedResult<GoogleAdsKeywordRow>  $keywords
     * @param  GoogleAdsPagedResult<GoogleAdsSearchTermRow>  $searchTerms
     * @param  array<string, mixed>  $budget  campaign DAILY budget facts (never the monthly target)
     */
    public function __construct(
        public readonly GoogleAdsCampaignRow $campaign,
        public readonly GoogleAdsPeriod $period,
        public readonly array $trend,
        public readonly array $adGroups,
        public readonly GoogleAdsPagedResult $keywords,
        public readonly GoogleAdsPagedResult $searchTerms,
        public readonly array $budget,
    ) {
    }
}
