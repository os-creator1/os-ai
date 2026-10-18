<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;

/**
 * Everything the campaign detail page shows, from cached rows.
 *
 * `trend` is the MetaAdsTrendSeries payload for this campaign; `adSets` and
 * `ads` are the campaign's children for the period (bounded by
 * MetaAdsCampaignReader::DETAIL_LIMIT; `*Total` carry the real counts);
 * `budget` is the campaign's own DAILY / LIFETIME budget facts, never the
 * Business monthly target.
 */
final class MetaAdsCampaignDetail
{
    /**
     * @param  array<string, mixed>  $trend
     * @param  array<int, MetaAdsAdSetRow>  $adSets
     * @param  array<int, MetaAdsAdRow>  $ads
     * @param  array<string, mixed>  $budget
     */
    public function __construct(
        public readonly MetaAdsCampaignRow $campaign,
        public readonly GoogleAdsPeriod $period,
        public readonly array $trend,
        public readonly array $adSets,
        public readonly int $adSetsTotal,
        public readonly array $ads,
        public readonly int $adsTotal,
        public readonly array $budget,
    ) {
    }
}
