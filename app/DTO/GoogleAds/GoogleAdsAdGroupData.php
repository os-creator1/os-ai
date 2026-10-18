<?php

namespace App\DTO\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §4 — an ad group and its parent campaign id.
 */
final readonly class GoogleAdsAdGroupData
{
    public function __construct(
        public string $externalAdGroupId,
        public string $externalCampaignId,
        public string $name,
        public GoogleAdsEntityStatus $status,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromSearchRow(array $row): ?self
    {
        $adGroup = $row['adGroup'] ?? null;
        $campaign = $row['campaign'] ?? null;

        if (! is_array($adGroup) || ! is_array($campaign)) {
            return null;
        }

        $id = J::id($adGroup['id'] ?? null);
        $campaignId = J::id($campaign['id'] ?? null);
        $name = J::string($adGroup['name'] ?? null, 255);

        if ($id === null || $campaignId === null || $name === null) {
            return null;
        }

        return new self($id, $campaignId, $name, GoogleAdsEntityStatus::fromProvider($adGroup['status'] ?? null));
    }
}
