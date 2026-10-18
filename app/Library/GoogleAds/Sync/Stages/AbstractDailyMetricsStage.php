<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsDailyMetricData;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;

/**
 * Daily facts (`google_ads_daily_metrics`), keyed (account, level, entity,
 * date). Facts are NEVER deleted: a day the provider omits is absence, not
 * zero, and an incomplete fetch must never erase history. Re-fetching a day
 * simply overwrites that day's row (replace by key).
 */
abstract class AbstractDailyMetricsStage extends AbstractGoogleAdsStage
{
    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        $stamp = $context->stamp();
        $rows = [];
        $latest = null;

        /** @var GoogleAdsDailyMetricData $metric */
        foreach ($report->rows as $metric) {
            $key = $metric->level->value . '|' . $metric->entityKey . '|' . $metric->date;
            $m = $metric->metrics;
            $existing = $rows[$key] ?? null;

            // The same key twice in one report is summed, never double-written.
            $rows[$key] = [
                'business_id' => $context->businessId(),
                'google_ads_account_id' => $context->accountId(),
                'level' => $metric->level->value,
                'entity_key' => $metric->entityKey,
                'metric_date' => $metric->date,
                'impressions' => ($existing['impressions'] ?? 0) + $m->impressions,
                'clicks' => ($existing['clicks'] ?? 0) + $m->clicks,
                'interactions' => ($existing['interactions'] ?? 0) + $m->interactions,
                'cost_micros' => ($existing['cost_micros'] ?? 0) + $m->costMicros,
                'conversions' => $this->addDecimal($existing['conversions'] ?? null, $m->conversions),
                'conversions_value' => $this->addDecimal($existing['conversions_value'] ?? null, $m->conversionsValue),
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];

            if ($latest === null || $metric->date > $latest) {
                $latest = $metric->date;
            }
        }

        $written = $this->upsertChunked(
            'google_ads_daily_metrics',
            array_values($rows),
            ['google_ads_account_id', 'level', 'entity_key', 'metric_date'],
            ['impressions', 'clicks', 'interactions', 'cost_micros', 'conversions', 'conversions_value', 'last_synced_at', 'updated_at'],
        );

        return new GoogleAdsStageResult($written, $report->truncated, $latest);
    }
}
