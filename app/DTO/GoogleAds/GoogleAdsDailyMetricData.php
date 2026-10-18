<?php

namespace App\DTO\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §4 — one per-day fact at campaign or keyword
 * level. `entityKey` is the campaign's external id, or the keyword's
 * resource-name tail (`{adGroupId}~{criterionId}`).
 */
final readonly class GoogleAdsDailyMetricData
{
    public function __construct(
        public GoogleAdsMetricLevel $level,
        public string $entityKey,
        public string $date,
        public GoogleAdsMetrics $metrics,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row  FROM campaign
     */
    public static function fromCampaignRow(array $row): ?self
    {
        $campaignId = J::id(J::dig($row, ['campaign', 'id']));
        $date = J::date(J::dig($row, ['segments', 'date']));

        if ($campaignId === null || $date === null) {
            return null;
        }

        return new self(GoogleAdsMetricLevel::Campaign, $campaignId, $date, GoogleAdsMetrics::fromProvider($row['metrics'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $row  FROM keyword_view
     */
    public static function fromKeywordRow(array $row): ?self
    {
        $criterionId = J::id(J::dig($row, ['adGroupCriterion', 'criterionId']));
        $adGroupId = J::id(J::dig($row, ['adGroup', 'id']));
        $date = J::date(J::dig($row, ['segments', 'date']));

        if ($criterionId === null || $adGroupId === null || $date === null) {
            return null;
        }

        return new self(
            GoogleAdsMetricLevel::Keyword,
            $adGroupId . '~' . $criterionId,
            $date,
            GoogleAdsMetrics::fromProvider($row['metrics'] ?? null),
        );
    }
}
