<?php

namespace App\Library\MetaAds\Sync\Stages;

/** Ad-level daily insights (never added to campaign rows). */
final class AdInsightsStage extends AbstractInsightsStage
{
    public function key(): string
    {
        return 'ad_insights';
    }

    protected function level(): string
    {
        return 'ad';
    }

    protected function entityTable(): string
    {
        return 'meta_ads_ads';
    }

    protected function externalColumn(): string
    {
        return 'external_ad_id';
    }
}
