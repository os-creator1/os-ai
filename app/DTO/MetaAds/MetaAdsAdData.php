<?php

namespace App\DTO\MetaAds;

use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §5 — one ad with the creative fields the UI
 * shows. `creativeThumbnailUrl` is either null or an https URL whose host the
 * client matched against config('meta_ads.thumbnail_hosts'); this DTO refuses
 * anything that is not https.
 */
final readonly class MetaAdsAdData
{
    public function __construct(
        public string $externalAdId,
        public string $externalCampaignId,
        public string $externalAdSetId,
        public string $name,
        public string $status,
        public ?string $effectiveStatus,
        public ?string $creativeTitle,
        public ?string $creativeBody,
        public ?string $creativeThumbnailUrl,
        public ?string $creativeObjectType,
    ) {
        if ($creativeThumbnailUrl !== null && ! str_starts_with($creativeThumbnailUrl, 'https://')) {
            throw new InvalidArgumentException('Creative thumbnail must be an https URL.');
        }
    }
}
