<?php

namespace App\Library\Ads\Decisions;

use App\Models\GoogleAdsAccount;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition Purpose + Ads Decisioning V1 — the provider's own delivery facts
 * (spend, impressions, clicks, provider-counted results) summed over EXACTLY
 * the campaigns the owner assigned to one purpose.
 *
 * Reads the cached daily tables the syncs fill, scoped by Business and by the
 * selected account; never calls a provider. Campaign-level rows only, as every
 * other Ads KPI. A purpose's campaigns are never merged with another purpose's.
 * Absence is null: Meta results are null until the owner chose a result type;
 * Google conversions are null when none were reported.
 *
 * @phpstan-type Delivery array{spend_micros: int, impressions: int, clicks: int, results: ?float}
 */
final class ProviderPurposeDeliveryReader
{
    /**
     * @param  list<int>  $campaignIds  local google_ads_campaigns ids assigned to the purpose
     * @return array{spend_micros: int, impressions: int, clicks: int, results: ?float}
     */
    public function google(GoogleAdsAccount $account, array $campaignIds, string $from, string $to): array
    {
        if ($campaignIds === []) {
            return $this->empty();
        }

        $keys = DB::table('google_ads_campaigns')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->whereIn('id', $campaignIds)
            ->pluck('external_campaign_id')
            ->map(fn ($k): string => (string) $k)
            ->all();

        if ($keys === []) {
            return $this->empty();
        }

        $row = DB::table('google_ads_daily_metrics')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('level', 'campaign')
            ->whereIn('entity_key', $keys)
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(cost_micros),0) AS spend, COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(clicks),0) AS clicks, SUM(conversions) AS conversions')
            ->first();

        return [
            'spend_micros' => (int) ($row->spend ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'results' => $row?->conversions === null ? null : (float) $row->conversions,
        ];
    }

    /**
     * @param  list<int>  $campaignIds  local meta_ads_campaigns ids assigned to the purpose
     * @return array{spend_micros: int, impressions: int, clicks: int, results: ?float}
     */
    public function meta(MetaAdsAccount $account, array $campaignIds, string $from, string $to): array
    {
        if ($campaignIds === []) {
            return $this->empty();
        }

        $row = DB::table('meta_ads_daily_insights')
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->where('level', 'campaign')
            ->whereIn('entity_id', $campaignIds)
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(spend_micros),0) AS spend, COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(COALESCE(link_clicks, clicks)),0) AS clicks')
            ->first();

        $type = is_string($account->result_action_type) ? trim($account->result_action_type) : '';
        $results = null;

        if ($type !== '') {
            $value = DB::table('meta_ads_daily_results')
                ->where('business_id', $account->business_id)
                ->where('meta_ads_account_id', $account->id)
                ->where('level', 'campaign')
                ->whereIn('entity_id', $campaignIds)
                ->where('action_type', $type)
                ->whereBetween('metric_date', [$from, $to])
                ->sum('results');

            $results = (float) $value;
        }

        return [
            'spend_micros' => (int) ($row->spend ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'results' => $results,
        ];
    }

    /** @return array{spend_micros: int, impressions: int, clicks: int, results: ?float} */
    private function empty(): array
    {
        return ['spend_micros' => 0, 'impressions' => 0, 'clicks' => 0, 'results' => null];
    }
}
