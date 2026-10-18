<?php

namespace App\Library\MetaAds\Sync\Stages;

/** Campaign-level daily insights: the ONLY level account KPIs sum, and the source of data_through_date. */
final class CampaignInsightsStage extends AbstractInsightsStage
{
    public function key(): string
    {
        return 'campaign_insights';
    }

    protected function level(): string
    {
        return 'campaign';
    }

    protected function entityTable(): string
    {
        return 'meta_ads_campaigns';
    }

    protected function externalColumn(): string
    {
        return 'external_campaign_id';
    }
}
