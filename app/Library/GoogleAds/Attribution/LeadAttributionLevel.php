<?php

namespace App\Library\GoogleAds\Attribution;

/**
 * Google Ads Module V1 contract §10 — how much a lead's origin is actually
 * known. Deliberately never claims a Google campaign or keyword: a click id
 * proves a Google Ads click, not which campaign (that needs click_view or
 * offline conversion import, deferred).
 */
enum LeadAttributionLevel: string
{
    case GoogleClick = 'google_click';
    case CampaignTags = 'campaign_tags';
    case NotCaptured = 'not_captured';

    public function label(): string
    {
        return match ($this) {
            self::GoogleClick => 'Google click ID captured',
            self::CampaignTags => 'Campaign tags only',
            self::NotCaptured => 'Source not captured',
        };
    }
}
