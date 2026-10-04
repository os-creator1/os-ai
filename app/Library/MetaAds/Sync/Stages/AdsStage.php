<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaAdsAdData;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ads with their read-only creative summary (`meta_ads_ads`). An ad whose
 * campaign or ad set this account does not hold locally — or whose ad set
 * belongs to a different campaign than the ad says — is SKIPPED and counted,
 * never invented. Meta's adset_id is immutable, so an ad never moves.
 */
final class AdsStage extends AbstractMetaAdsStage
{
    public function key(): string
    {
        return 'ads';
    }

    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport
    {
        return $this->paged(fn (?string $cursor) => $this->client->ads(
            $context->accessToken(),
            $context->adAccountId(),
            $cursor,
        ));
    }

    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult
    {
        $stamp = $context->stamp();
        $campaigns = $this->idMap('meta_ads_campaigns', 'external_campaign_id', $context);
        $adSets = DB::table('meta_ads_ad_sets')
            ->where('meta_ads_account_id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->get(['id', 'external_ad_set_id', 'meta_ads_campaign_id'])
            ->keyBy('external_ad_set_id');

        $rows = [];
        $skipped = 0;

        /** @var MetaAdsAdData $ad */
        foreach ($report->rows as $ad) {
            $campaignId = $campaigns[$ad->externalCampaignId] ?? null;
            $adSet = $adSets->get($ad->externalAdSetId);

            if ($campaignId === null || $adSet === null || (int) $adSet->meta_ads_campaign_id !== $campaignId) {
                $skipped++;

                continue;
            }

            $rows[$ad->externalAdId] = [
                'uid' => (string) Str::uuid(),
                'business_id' => $context->businessId(),
                'meta_ads_account_id' => $context->accountId(),
                'meta_ads_campaign_id' => $campaignId,
                'meta_ads_ad_set_id' => (int) $adSet->id,
                'external_ad_id' => $ad->externalAdId,
                'name' => mb_substr($ad->name, 0, 255),
                'status' => $ad->status,
                'effective_status' => $ad->effectiveStatus,
                'creative_title' => $ad->creativeTitle === null ? null : mb_substr($ad->creativeTitle, 0, 255),
                'creative_body' => $ad->creativeBody,
                'creative_thumbnail_url' => $ad->creativeThumbnailUrl,
                'creative_object_type' => $ad->creativeObjectType === null ? null : mb_substr($ad->creativeObjectType, 0, 40),
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'meta_ads_ads',
            array_values($rows),
            ['meta_ads_account_id', 'external_ad_id'],
            ['meta_ads_campaign_id', 'meta_ads_ad_set_id', 'name', 'status', 'effective_status', 'creative_title', 'creative_body', 'creative_thumbnail_url', 'creative_object_type', 'last_synced_at', 'updated_at'],
        );

        if (! $report->truncated && $skipped === 0) {
            $this->markUnseenGone('meta_ads_ads', $context);
        }

        return new MetaAdsStageResult($written, $report->truncated, null, $skipped);
    }
}
