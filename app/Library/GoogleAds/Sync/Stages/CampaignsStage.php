<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsCampaignData;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;
use Illuminate\Support\Str;

/** Campaigns plus their budget facts (`google_ads_campaigns`). */
final class CampaignsStage extends AbstractGoogleAdsStage
{
    public function key(): string
    {
        return 'campaigns';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return $this->client->campaigns($context->access);
    }

    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        $stamp = $context->stamp();
        $rows = [];

        /** @var GoogleAdsCampaignData $campaign */
        foreach ($report->rows as $campaign) {
            $rows[$campaign->externalCampaignId] = [
                'uid' => (string) Str::uuid(),
                'business_id' => $context->businessId(),
                'google_ads_account_id' => $context->accountId(),
                'external_campaign_id' => $campaign->externalCampaignId,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'channel_type' => $campaign->channelType,
                'bidding_strategy_type' => $campaign->biddingStrategyType,
                'budget_external_id' => $campaign->budgetExternalId,
                'budget_amount_micros' => $campaign->budgetAmountMicros,
                'budget_shared' => $campaign->budgetShared,
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'google_ads_campaigns',
            array_values($rows),
            ['google_ads_account_id', 'external_campaign_id'],
            ['name', 'status', 'channel_type', 'bidding_strategy_type', 'budget_external_id', 'budget_amount_micros', 'budget_shared', 'last_synced_at', 'updated_at'],
        );

        if (! $report->truncated) {
            $this->markUnseenRemoved('google_ads_campaigns', $context);
        }

        return new GoogleAdsStageResult($written, $report->truncated);
    }
}
