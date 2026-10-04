<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;

/**
 * Keyword-level daily metrics (from `keyword_view`). Never summed into
 * account KPIs (they would double count); the data-through date comes from
 * the campaign stage only.
 */
final class KeywordMetricsStage extends AbstractDailyMetricsStage
{
    public function key(): string
    {
        return 'keyword_metrics';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return $this->client->dailyKeywordMetrics($context->access, $context->metricsStart, $context->endDate);
    }

    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        $result = parent::persist($context, $report);

        return new GoogleAdsStageResult($result->rows, $result->truncated);
    }
}
