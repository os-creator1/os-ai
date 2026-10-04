<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaAdsCampaignData;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use Illuminate\Support\Str;

/** Campaigns plus their budget facts (`meta_ads_campaigns`). Budgets are MINOR units; absent stays NULL. */
final class CampaignsStage extends AbstractMetaAdsStage
{
    public function key(): string
    {
        return 'campaigns';
    }

    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport
    {
        return $this->paged(fn (?string $cursor) => $this->client->campaigns(
            $context->accessToken(),
            $context->adAccountId(),
            $cursor,
        ));
    }

    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult
    {
        $stamp = $context->stamp();
        $rows = [];

        /** @var MetaAdsCampaignData $campaign */
        foreach ($report->rows as $campaign) {
            $rows[$campaign->externalCampaignId] = [
                'uid' => (string) Str::uuid(),
                'business_id' => $context->businessId(),
                'meta_ads_account_id' => $context->accountId(),
                'external_campaign_id' => $campaign->externalCampaignId,
                'name' => mb_substr($campaign->name, 0, 255),
                'status' => $campaign->status,
                'effective_status' => $campaign->effectiveStatus,
                'objective' => $campaign->objective,
                'daily_budget_minor' => $campaign->dailyBudgetMinor,
                'lifetime_budget_minor' => $campaign->lifetimeBudgetMinor,
                'budget_remaining_minor' => $campaign->budgetRemainingMinor,
                'start_time' => $this->dateTime($campaign->startTime),
                'stop_time' => $this->dateTime($campaign->stopTime),
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'meta_ads_campaigns',
            array_values($rows),
            ['meta_ads_account_id', 'external_campaign_id'],
            ['name', 'status', 'effective_status', 'objective', 'daily_budget_minor', 'lifetime_budget_minor', 'budget_remaining_minor', 'start_time', 'stop_time', 'last_synced_at', 'updated_at'],
        );

        if (! $report->truncated) {
            $this->markUnseenGone('meta_ads_campaigns', $context);
        }

        return new MetaAdsStageResult($written, $report->truncated);
    }
}
