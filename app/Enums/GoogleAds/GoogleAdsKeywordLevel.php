<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §4 — where a keyword lives. Positive keywords are always
 * `ad_group`; negatives can be either. Also the scope of a new negative
 * keyword (§6: campaign by default, ad group when the term has one).
 */
enum GoogleAdsKeywordLevel: string
{
    case AdGroup = 'ad_group';
    case Campaign = 'campaign';
}
