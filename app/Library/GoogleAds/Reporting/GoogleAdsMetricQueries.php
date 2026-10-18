<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Models\GoogleAdsAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 — the single place that reads google_ads_daily_metrics.
 *
 * EVERY query here is scoped by business_id AND google_ads_account_id and a
 * metric level, so an aggregate can never cross a Business, an account (and
 * therefore a currency) or double count campaign + keyword rows. Cache-only:
 * nothing in Reporting ever calls a provider client.
 */
final class GoogleAdsMetricQueries
{
    /** Aggregate select list; column names are what GoogleAdsMetricTotals::fromRow reads. */
    public const AGGREGATE_COLUMNS = 'SUM(cost_micros) AS spend_micros, SUM(clicks) AS clicks, SUM(impressions) AS impressions, '
        . 'SUM(conversions) AS conversions, SUM(conversions_value) AS conversions_value, '
        . 'COUNT(*) AS row_count, COUNT(DISTINCT metric_date) AS day_count, MAX(metric_date) AS last_date';

    /** Sort keys shared by the campaign and keyword tables. */
    public const METRIC_SORTS = ['spend', 'clicks', 'impressions', 'conversions', 'cpl', 'conversion_rate', 'conversion_value'];

    /** Scoped base query over one level and an inclusive date range. */
    public function daily(GoogleAdsAccount $account, GoogleAdsMetricLevel $level, string $from, string $to): Builder
    {
        return DB::table('google_ads_daily_metrics')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('level', $level->value)
            ->whereBetween('metric_date', [$from, $to]);
    }

    /** One summed block (optionally one entity); empty() when no rows. */
    public function totals(GoogleAdsAccount $account, GoogleAdsMetricLevel $level, string $from, string $to, ?string $entityKey = null): GoogleAdsMetricTotals
    {
        $query = $this->daily($account, $level, $from, $to)->selectRaw(self::AGGREGATE_COLUMNS);

        if ($entityKey !== null) {
            $query->where('entity_key', $entityKey);
        }

        return GoogleAdsMetricTotals::fromRow($query->first());
    }

    /** Grouped-by-entity sub-select, for left-joining onto a campaign/keyword table as `m`. */
    public function perEntity(GoogleAdsAccount $account, GoogleAdsMetricLevel $level, string $from, string $to): Builder
    {
        return $this->daily($account, $level, $from, $to)
            ->selectRaw('entity_key, ' . self::AGGREGATE_COLUMNS)
            ->groupBy('entity_key');
    }

    /**
     * Null-last ORDER BY expression over an aliased aggregate sub-select
     * (columns spend_micros, clicks, ...), or null for an unknown key.
     */
    public static function metricSortExpression(string $key, string $alias = 'm'): ?string
    {
        return match ($key) {
            'spend' => "{$alias}.spend_micros",
            'clicks' => "{$alias}.clicks",
            'impressions' => "{$alias}.impressions",
            'conversions' => "{$alias}.conversions",
            'conversion_value' => "{$alias}.conversions_value",
            'cpl' => "CASE WHEN {$alias}.conversions > 0 THEN {$alias}.spend_micros / {$alias}.conversions END",
            'conversion_rate' => "CASE WHEN {$alias}.clicks > 0 AND {$alias}.conversions IS NOT NULL THEN {$alias}.conversions / {$alias}.clicks END",
            default => null,
        };
    }

    public static function direction(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }

    /** Normalises the page window (page >= 1, 1 <= perPage <= 200). */
    public static function window(int $page, int $perPage): array
    {
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
