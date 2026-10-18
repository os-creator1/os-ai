<?php

namespace App\DTO\MetaAds;

use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract §5 — one campaign. `status` is Meta's
 * configured status (ACTIVE|PAUSED|DELETED|ARCHIVED), `effectiveStatus` the
 * delivery status (ACTIVE, PAUSED, WITH_ISSUES, ...). Both are Meta's
 * upper-case strings, stored as-is. Budgets are integer MINOR units; null =
 * not set (a campaign has a daily OR a lifetime budget, or the budget lives on
 * its ad sets).
 */
final readonly class MetaAdsCampaignData
{
    public function __construct(
        public string $externalCampaignId,
        public string $name,
        public string $status,
        public ?string $effectiveStatus,
        public ?string $objective,
        public ?int $dailyBudgetMinor,
        public ?int $lifetimeBudgetMinor,
        public ?int $budgetRemainingMinor,
        public ?CarbonImmutable $startTime,
        public ?CarbonImmutable $stopTime,
    ) {
    }
}
