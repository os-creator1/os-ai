<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsKeywordData;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;
use Illuminate\Support\Str;

/**
 * Positive keywords and ad-group / campaign negatives (`google_ads_keywords`),
 * keyed (account, level, external criterion id). The client merges its three
 * reports and sets `truncated` if any was capped, so one truncated flag
 * protects all three from removal inference.
 */
final class KeywordsStage extends AbstractGoogleAdsStage
{
    public function key(): string
    {
        return 'keywords';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return $this->client->keywords($context->access);
    }

    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        $stamp = $context->stamp();
        $campaigns = $this->idMap('google_ads_campaigns', 'external_campaign_id', $context);
        $adGroups = $this->idMap('google_ads_ad_groups', 'external_ad_group_id', $context);
        $rows = [];
        $skipped = 0;

        /** @var GoogleAdsKeywordData $keyword */
        foreach ($report->rows as $keyword) {
            $campaignId = $campaigns[$keyword->externalCampaignId] ?? null;
            $adGroupId = $keyword->externalAdGroupId === null ? null : ($adGroups[$keyword->externalAdGroupId] ?? null);

            if ($campaignId === null || ($keyword->level === GoogleAdsKeywordLevel::AdGroup && $adGroupId === null)) {
                $skipped++;

                continue;
            }

            $rows[$keyword->level->value . '|' . $keyword->externalCriterionId] = [
                'uid' => (string) Str::uuid(),
                'business_id' => $context->businessId(),
                'google_ads_account_id' => $context->accountId(),
                'google_ads_campaign_id' => $campaignId,
                'google_ads_ad_group_id' => $keyword->level === GoogleAdsKeywordLevel::AdGroup ? $adGroupId : null,
                'external_criterion_id' => $keyword->externalCriterionId,
                'text' => $keyword->text,
                'match_type' => $keyword->matchType->value,
                'status' => $keyword->status->value,
                'is_negative' => $keyword->isNegative,
                'level' => $keyword->level->value,
                'quality_score' => $keyword->qualityScore,
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'google_ads_keywords',
            array_values($rows),
            ['google_ads_account_id', 'level', 'external_criterion_id'],
            ['google_ads_campaign_id', 'google_ads_ad_group_id', 'text', 'match_type', 'status', 'is_negative', 'quality_score', 'last_synced_at', 'updated_at'],
        );

        if (! $report->truncated && $skipped === 0) {
            $this->markUnseenRemoved('google_ads_keywords', $context);
        }

        return new GoogleAdsStageResult($written, $report->truncated);
    }
}
