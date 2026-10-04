<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaAdsAdSetData;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use Illuminate\Support\Str;

/**
 * Ad sets (`meta_ads_ad_sets`). An ad set whose campaign this account does not
 * hold locally is SKIPPED and counted, never invented, and a listing with any
 * skipped row is not used to mark anything gone.
 *
 * reach_7d / frequency_7d / frequency_window_end are NOT touched here: they
 * belong to the frequency stage, so a re-sync of the list never erases them.
 */
final class AdSetsStage extends AbstractMetaAdsStage
{
    public function key(): string
    {
        return 'ad_sets';
    }

    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport
    {
        return $this->paged(fn (?string $cursor) => $this->client->adSets(
            $context->accessToken(),
            $context->adAccountId(),
            $cursor,
        ));
    }

    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult
    {
        $stamp = $context->stamp();
        $campaigns = $this->idMap('meta_ads_campaigns', 'external_campaign_id', $context);
        $rows = [];
        $skipped = 0;

        /** @var MetaAdsAdSetData $adSet */
        foreach ($report->rows as $adSet) {
            $campaignId = $campaigns[$adSet->externalCampaignId] ?? null;

            if ($campaignId === null) {
                $skipped++;

                continue;
            }

            $rows[$adSet->externalAdSetId] = [
                'uid' => (string) Str::uuid(),
                'business_id' => $context->businessId(),
                'meta_ads_account_id' => $context->accountId(),
                'meta_ads_campaign_id' => $campaignId,
                'external_ad_set_id' => $adSet->externalAdSetId,
                'name' => mb_substr($adSet->name, 0, 255),
                'status' => $adSet->status,
                'effective_status' => $adSet->effectiveStatus,
                'daily_budget_minor' => $adSet->dailyBudgetMinor,
                'lifetime_budget_minor' => $adSet->lifetimeBudgetMinor,
                'optimization_goal' => $adSet->optimizationGoal,
                'bid_strategy' => $adSet->bidStrategy,
                'targeting_summary' => $adSet->targetingSummary === null ? null : mb_substr($adSet->targetingSummary, 0, 255),
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'meta_ads_ad_sets',
            array_values($rows),
            ['meta_ads_account_id', 'external_ad_set_id'],
            ['meta_ads_campaign_id', 'name', 'status', 'effective_status', 'daily_budget_minor', 'lifetime_budget_minor', 'optimization_goal', 'bid_strategy', 'targeting_summary', 'last_synced_at', 'updated_at'],
        );

        if (! $report->truncated && $skipped === 0) {
            $this->markUnseenGone('meta_ads_ad_sets', $context);
        }

        return new MetaAdsStageResult($written, $report->truncated, null, $skipped);
    }
}
