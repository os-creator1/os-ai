<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Models\MetaAdsAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §5 — the single place that reads
 * meta_ads_daily_insights.
 *
 * EVERY query is scoped by business_id AND meta_ads_account_id AND a level,
 * so an aggregate can never cross a Business, an account (and therefore a
 * currency) or double count campaign + ad-set + ad rows. Account KPIs use the
 * Campaign level only. Cache-only: nothing here ever calls a provider.
 */
final class MetaAdsMetricQueries
{
    public const AGGREGATE_COLUMNS = 'SUM(spend_micros) AS spend_micros, SUM(impressions) AS impressions, SUM(clicks) AS clicks, '
        . 'SUM(link_clicks) AS link_clicks, COUNT(*) AS row_count, COUNT(DISTINCT metric_date) AS day_count, MAX(metric_date) AS last_date';

    /** Sort keys shared by the campaign / ad set / ad tables. */
    public const METRIC_SORTS = ['spend', 'impressions', 'clicks', 'link_clicks', 'results', 'cost_per_result', 'ctr', 'cpc', 'cpm'];

    public function __construct(private readonly MetaAdsResultQueries $results)
    {
    }

    public function results(): MetaAdsResultQueries
    {
        return $this->results;
    }

    /** Scoped base query over one level and an inclusive date range. */
    public function daily(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to): Builder
    {
        return DB::table('meta_ads_daily_insights')
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->where('level', $level->value)
            ->whereBetween('metric_date', [$from, $to]);
    }

    /**
     * One summed block (optionally one local entity) including the chosen
     * result type. 1 query, +1 when a result type is chosen, +1 only when an
     * entity block has no result rows and the "is this a real zero?" question
     * must be answered.
     */
    public function totals(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to, ?int $entityId = null): MetaAdsMetricTotals
    {
        $chosen = $this->results->chosenType($account) !== null;
        $query = $this->daily($account, $level, $from, $to)->selectRaw(self::AGGREGATE_COLUMNS);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        $row = $query->first();

        if ($row === null || (int) $row->row_count === 0) {
            return MetaAdsMetricTotals::empty($chosen);
        }

        $resultRow = $this->results->totals($account, $level, $from, $to, $entityId);

        if ($resultRow !== null) {
            $row->results = $resultRow->results;
            $row->result_value = $resultRow->result_value;
            $row->result_row_count = $resultRow->result_row_count;
        }

        $present = false;

        if ($chosen && (int) ($row->result_row_count ?? 0) === 0 && $entityId !== null) {
            $present = $this->results->hasData($account, $level, $from, $to);
        }

        return MetaAdsMetricTotals::fromRow($row, $chosen, $present);
    }

    /** Grouped-by-entity sub-select, to left-join onto an entity table as `m`. */
    public function perEntity(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to): Builder
    {
        return $this->daily($account, $level, $from, $to)
            ->selectRaw('entity_id, ' . self::AGGREGATE_COLUMNS)
            ->groupBy('entity_id');
    }

    /**
     * Left-joins the insight aggregate as `m` (and the chosen-type result
     * aggregate as `r`) onto $query, keyed by $entityColumn, and adds the
     * metric columns to the select list. ONE aggregate subquery per source
     * regardless of the number of entities (no N+1).
     *
     * @return bool whether a result type is chosen
     */
    public function joinEntityMetrics(Builder $query, MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to, string $entityColumn): bool
    {
        $type = $this->results->chosenType($account);

        $query->leftJoinSub($this->perEntity($account, $level, $from, $to), 'm', 'm.entity_id', '=', $entityColumn);

        if ($type !== null) {
            $query->leftJoinSub($this->results->perEntity($account, $level, $from, $to, $type), 'r', 'r.entity_id', '=', $entityColumn);
            $query->selectRaw('m.spend_micros, m.impressions, m.clicks, m.link_clicks, m.row_count, m.day_count, m.last_date, '
                . 'r.results, r.result_value, r.result_row_count');
        } else {
            $query->selectRaw('m.spend_micros, m.impressions, m.clicks, m.link_clicks, m.row_count, m.day_count, m.last_date, '
                . 'NULL AS results, NULL AS result_value, 0 AS result_row_count');
        }

        return $type !== null;
    }

    /**
     * Null-last ORDER BY expression over the `m` / `r` aggregates, or null
     * for an unknown key. Result sorts degrade to a constant NULL (stable
     * secondary order) when no result type is chosen.
     */
    public static function metricSortExpression(string $key, bool $resultTypeChosen = true): ?string
    {
        return match ($key) {
            'spend' => 'm.spend_micros',
            'impressions' => 'm.impressions',
            'clicks' => 'm.clicks',
            'link_clicks' => 'm.link_clicks',
            'results' => $resultTypeChosen ? 'r.results' : 'NULL',
            'cost_per_result' => $resultTypeChosen ? 'CASE WHEN r.results > 0 THEN m.spend_micros / r.results END' : 'NULL',
            'ctr' => 'CASE WHEN m.impressions > 0 AND m.link_clicks IS NOT NULL THEN m.link_clicks / m.impressions END',
            'cpc' => 'CASE WHEN m.link_clicks > 0 THEN m.spend_micros / m.link_clicks END',
            'cpm' => 'CASE WHEN m.impressions > 0 THEN m.spend_micros / m.impressions END',
            default => null,
        };
    }

    public static function direction(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }

    /** Normalises the page window (page >= 1, 1 <= perPage <= 200). @return array{0:int,1:int,2:int} */
    public static function window(int $page, int $perPage): array
    {
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * Whether the chosen-type results of a level/window should zero-fill an
     * entity that has insight rows but no result rows (see MetaAdsMetricTotals).
     */
    public function resultDataPresent(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to): bool
    {
        return $this->results->hasData($account, $level, $from, $to);
    }
}
