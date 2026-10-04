<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §5 — the entity level of a daily insight / result row. Account
 * KPIs sum Campaign rows only.
 */
enum MetaAdsLevel: string
{
    case Campaign = 'campaign';
    case AdSet = 'ad_set';
    case Ad = 'ad';
}
