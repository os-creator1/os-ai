<?php

namespace App\DTO\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §2/§4 — a campaign plus its budget facts.
 * `budgetAmountMicros` is the DAILY campaign budget (display fact only).
 */
final readonly class GoogleAdsCampaignData
{
    public function __construct(
        public string $externalCampaignId,
        public string $name,
        public GoogleAdsEntityStatus $status,
        public ?string $channelType,
        public ?string $biddingStrategyType,
        public ?string $budgetExternalId,
        public ?int $budgetAmountMicros,
        public ?bool $budgetShared,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromSearchRow(array $row): ?self
    {
        $campaign = $row['campaign'] ?? null;

        if (! is_array($campaign)) {
            return null;
        }

        $id = J::id($campaign['id'] ?? null);
        $name = J::string($campaign['name'] ?? null, 255);

        if ($id === null || $name === null) {
            return null;
        }

        $budget = is_array($row['campaignBudget'] ?? null) ? $row['campaignBudget'] : [];

        return new self(
            externalCampaignId: $id,
            name: $name,
            status: GoogleAdsEntityStatus::fromProvider($campaign['status'] ?? null),
            channelType: J::string($campaign['advertisingChannelType'] ?? null, 40),
            biddingStrategyType: J::string($campaign['biddingStrategyType'] ?? null, 60),
            budgetExternalId: J::id($budget['id'] ?? null) ?? J::resourceTail($campaign['campaignBudget'] ?? null),
            budgetAmountMicros: J::unsignedInt64($budget['amountMicros'] ?? null),
            budgetShared: J::bool($budget['explicitlyShared'] ?? null),
        );
    }
}
