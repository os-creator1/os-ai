<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;

/** Campaign-level daily metrics: the rows every account KPI is summed from. */
final class CampaignMetricsStage extends AbstractDailyMetricsStage
{
    public function key(): string
    {
        return 'campaign_metrics';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return $this->client->dailyCampaignMetrics($context->access, $context->metricsStart, $context->endDate);
    }
}
