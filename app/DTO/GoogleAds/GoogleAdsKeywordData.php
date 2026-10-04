<?php

namespace App\DTO\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §2/§4 — a positive or negative keyword at ad
 * group or campaign level.
 *
 * `externalCriterionId` is the resource-name TAIL (`{adGroupId}~{criterionId}`
 * or `{campaignId}~{criterionId}`): a bare criterion id is only unique within
 * its parent. `qualityScore` is null unless the API returned one.
 */
final readonly class GoogleAdsKeywordData
{
    public function __construct(
        public string $externalCriterionId,
        public GoogleAdsKeywordLevel $level,
        public string $externalCampaignId,
        public ?string $externalAdGroupId,
        public string $text,
        public GoogleAdsMatchType $matchType,
        public GoogleAdsEntityStatus $status,
        public bool $isNegative,
        public ?int $qualityScore,
    ) {
    }

    /**
     * An `ad_group_criterion` row (positive or negative). A row that is not a
     * keyword (no keyword.text / match type) yields null and is skipped.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromAdGroupCriterionRow(array $row): ?self
    {
        $criterion = $row['adGroupCriterion'] ?? null;
        $adGroup = $row['adGroup'] ?? null;
        $campaign = $row['campaign'] ?? null;

        if (! is_array($criterion) || ! is_array($adGroup) || ! is_array($campaign)) {
            return null;
        }

        $criterionId = J::id($criterion['criterionId'] ?? null);
        $adGroupId = J::id($adGroup['id'] ?? null);
        $campaignId = J::id($campaign['id'] ?? null);
        $text = J::string(J::dig($criterion, ['keyword', 'text']), 255);
        $match = GoogleAdsMatchType::fromProvider(J::dig($criterion, ['keyword', 'matchType']));

        if ($criterionId === null || $adGroupId === null || $campaignId === null || $text === null || $match === null) {
            return null;
        }

        $quality = J::int64(J::dig($criterion, ['qualityInfo', 'qualityScore']));

        return new self(
            externalCriterionId: $adGroupId . '~' . $criterionId,
            level: GoogleAdsKeywordLevel::AdGroup,
            externalCampaignId: $campaignId,
            externalAdGroupId: $adGroupId,
            text: $text,
            matchType: $match,
            status: GoogleAdsEntityStatus::fromProvider($criterion['status'] ?? null),
            isNegative: J::bool($criterion['negative'] ?? null) === true,
            qualityScore: $quality !== null && $quality >= 1 && $quality <= 10 ? $quality : null,
        );
    }

    /**
     * A `campaign_criterion` row (campaign-level negative keyword). Other
     * criterion kinds (locations, etc.) carry no keyword and are skipped.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromCampaignCriterionRow(array $row): ?self
    {
        $criterion = $row['campaignCriterion'] ?? null;
        $campaign = $row['campaign'] ?? null;

        if (! is_array($criterion) || ! is_array($campaign)) {
            return null;
        }

        $criterionId = J::id($criterion['criterionId'] ?? null);
        $campaignId = J::id($campaign['id'] ?? null);
        $text = J::string(J::dig($criterion, ['keyword', 'text']), 255);
        $match = GoogleAdsMatchType::fromProvider(J::dig($criterion, ['keyword', 'matchType']));

        if ($criterionId === null || $campaignId === null || $text === null || $match === null) {
            return null;
        }

        return new self(
            externalCriterionId: $campaignId . '~' . $criterionId,
            level: GoogleAdsKeywordLevel::Campaign,
            externalCampaignId: $campaignId,
            externalAdGroupId: null,
            text: $text,
            matchType: $match,
            status: GoogleAdsEntityStatus::fromProvider($criterion['status'] ?? null),
            isNegative: J::bool($criterion['negative'] ?? null) === true,
            qualityScore: null,
        );
    }
}
