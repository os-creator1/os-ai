<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaInsightRow;
use App\Library\MetaAds\MetaAdsMoney;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;

/**
 * Daily facts at ONE level (`meta_ads_daily_insights`, plus the typed
 * `meta_ads_daily_results`), attached to LOCAL entity ids.
 *
 *  - A row for an entity this account does not hold locally is SKIPPED and
 *    counted — an entity is never invented to hold a fact.
 *  - An absent metric stays NULL (link clicks, a result's value); a row that
 *    lacks spend / impressions / clicks (NOT NULL columns that Meta always
 *    sends) is skipped rather than written as 0.
 *  - Only action types in config('meta_ads.result_types') are stored (the
 *    client already filters; this is the second, independent filter). A
 *    result's `value` is MICROS in the account currency and is stored as the
 *    exact decimal major-unit string the readers expect.
 *  - Facts are NEVER deleted: a day Meta omits is absence, not zero, and an
 *    incomplete fetch must never erase history. Re-fetching a day overwrites
 *    that day's row (replace by key), so re-running a window changes nothing.
 */
abstract class AbstractInsightsStage extends AbstractMetaAdsStage
{
    /** @return 'campaign'|'ad_set'|'ad' */
    abstract protected function level(): string;

    abstract protected function entityTable(): string;

    abstract protected function externalColumn(): string;

    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport
    {
        return $this->paged(fn (?string $cursor) => $this->client->insights(
            $context->accessToken(),
            $context->adAccountId(),
            $this->level(),
            $context->metricsStart,
            $context->metricsEnd,
            $cursor,
        ));
    }

    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult
    {
        $stamp = $context->stamp();
        $entities = $this->idMap($this->entityTable(), $this->externalColumn(), $context);

        $insights = [];
        $results = [];
        $skipped = 0;
        $latest = null;

        /** @var MetaInsightRow $row */
        foreach ($report->rows as $row) {
            $entityId = $entities[$row->externalId] ?? null;

            if ($entityId === null
                || $row->level !== $this->level()
                || $row->spendMicros === null
                || $row->impressions === null
                || $row->clicks === null) {
                $skipped++;

                continue;
            }

            $key = $entityId . '|' . $row->date;

            $insights[$key] = [
                'business_id' => $context->businessId(),
                'meta_ads_account_id' => $context->accountId(),
                'level' => $this->level(),
                'entity_id' => $entityId,
                'metric_date' => $row->date,
                'spend_micros' => $row->spendMicros,
                'impressions' => $row->impressions,
                'clicks' => $row->clicks,
                'link_clicks' => $row->linkClicks,
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];

            foreach ($row->results as $type => $result) {
                if (! $this->config->isResultType((string) $type)) {
                    continue;
                }

                $results[$key . '|' . $type] = [
                    'business_id' => $context->businessId(),
                    'meta_ads_account_id' => $context->accountId(),
                    'level' => $this->level(),
                    'entity_id' => $entityId,
                    'metric_date' => $row->date,
                    'action_type' => (string) $type,
                    'results' => (string) $result['count'],
                    'result_value' => MetaAdsMoney::microsToDecimalString($result['value'] ?? null),
                    'last_synced_at' => $stamp,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }

            if ($latest === null || $row->date > $latest) {
                $latest = $row->date;
            }
        }

        $written = $this->upsertChunked(
            'meta_ads_daily_insights',
            array_values($insights),
            ['meta_ads_account_id', 'level', 'entity_id', 'metric_date'],
            ['spend_micros', 'impressions', 'clicks', 'link_clicks', 'last_synced_at', 'updated_at'],
        );

        $this->upsertChunked(
            'meta_ads_daily_results',
            array_values($results),
            ['meta_ads_account_id', 'level', 'entity_id', 'metric_date', 'action_type'],
            ['results', 'result_value', 'last_synced_at', 'updated_at'],
        );

        return new MetaAdsStageResult($written, $report->truncated, $this->level() === 'campaign' ? $latest : null, $skipped);
    }
}
