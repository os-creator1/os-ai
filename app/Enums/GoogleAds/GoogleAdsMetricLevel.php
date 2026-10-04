<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §4 — the grain of a google_ads_daily_metrics row. Account KPIs
 * sum `Campaign` rows only, never `Keyword` rows, to avoid double counting.
 */
enum GoogleAdsMetricLevel: string
{
    case Campaign = 'campaign';
    case Keyword = 'keyword';
}
