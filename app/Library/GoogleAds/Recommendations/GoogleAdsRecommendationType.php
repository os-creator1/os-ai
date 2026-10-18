<?php

namespace App\Library\GoogleAds\Recommendations;

/** Contract §12 — the closed set of deterministic recommendation fact types. */
enum GoogleAdsRecommendationType: string
{
    case WastedSearchTerms = 'wasted_search_terms';
    case ZeroConversionCampaign = 'zero_conversion_campaign';
    case CplAboveTarget = 'cpl_above_target';
    case StrongCampaign = 'strong_campaign';
    case PacingOver = 'pacing_over';
    case PacingUnder = 'pacing_under';
}
