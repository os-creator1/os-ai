<?php

namespace App\Library\MetaAds\Sync\Stages;

/** Ad-set-level daily insights (never added to campaign rows). */
final class AdSetInsightsStage extends AbstractInsightsStage
{
    public function key(): string
    {
        return 'ad_set_insights';
    }

    protected function level(): string
    {
        return 'ad_set';
    }

    protected function entityTable(): string
    {
        return 'meta_ads_ad_sets';
    }

    protected function externalColumn(): string
    {
        return 'external_ad_set_id';
    }
}
