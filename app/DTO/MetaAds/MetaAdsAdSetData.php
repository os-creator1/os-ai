<?php

namespace App\DTO\MetaAds;

/**
 * Meta Ads Module V1 contract §5 — one ad set. `targetingSummary` is built
 * SERVER-SIDE from Meta's targeting spec (<= 255 chars, e.g.
 * "Ages 25–54 · US"); the raw spec is never stored or shown.
 */
final readonly class MetaAdsAdSetData
{
    public function __construct(
        public string $externalAdSetId,
        public string $externalCampaignId,
        public string $name,
        public string $status,
        public ?string $effectiveStatus,
        public ?int $dailyBudgetMinor,
        public ?int $lifetimeBudgetMinor,
        public ?string $optimizationGoal,
        public ?string $bidStrategy,
        public ?string $targetingSummary,
    ) {
    }
}
