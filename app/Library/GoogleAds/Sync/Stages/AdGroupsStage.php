<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsAdGroupData;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;

/** Ad groups (`google_ads_ad_groups`), attached to campaigns synced earlier in the run. */
final class AdGroupsStage extends AbstractGoogleAdsStage
{
    public function key(): string
    {
        return 'ad_groups';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return $this->client->adGroups($context->access);
    }

    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        $stamp = $context->stamp();
        $campaigns = $this->idMap('google_ads_campaigns', 'external_campaign_id', $context);
        $rows = [];
        $skipped = 0;

        /** @var GoogleAdsAdGroupData $adGroup */
        foreach ($report->rows as $adGroup) {
            $campaignId = $campaigns[$adGroup->externalCampaignId] ?? null;

            if ($campaignId === null) {
                $skipped++;

                continue;
            }

            $rows[$adGroup->externalAdGroupId] = [
                'business_id' => $context->businessId(),
                'google_ads_account_id' => $context->accountId(),
                'google_ads_campaign_id' => $campaignId,
                'external_ad_group_id' => $adGroup->externalAdGroupId,
                'name' => $adGroup->name,
                'status' => $adGroup->status->value,
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'google_ads_ad_groups',
            array_values($rows),
            ['google_ads_account_id', 'external_ad_group_id'],
            ['google_ads_campaign_id', 'name', 'status', 'last_synced_at', 'updated_at'],
        );

        // Absence means "removed" only for a complete listing that was fully stored.
        if (! $report->truncated && $skipped === 0) {
            $this->markUnseenRemoved('google_ads_ad_groups', $context);
        }

        return new GoogleAdsStageResult($written, $report->truncated);
    }
}
