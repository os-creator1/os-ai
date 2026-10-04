<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsMoney;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsCampaign;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract 24 §5 — the daily chart payload, from cached
 * CAMPAIGN-level rows only. Same JSON shape as GoogleAdsTrendSeries (labels,
 * tooltips, series) so the page can reuse the theme chart helpers.
 *
 * Series (one value per label):
 *   spend / spend_micros   zero-filled for days without a row (visual continuity)
 *   impressions, clicks    int, zero-filled
 *   link_clicks            int|null  (null = no row or Meta returned none)
 *   results                float|null
 *   cost_per_result        float|null (major units)
 *
 * Gap rules: results are null when no result type is chosen; for a day WITH
 * an insight row they are 0 only when the account has chosen-type result rows
 * somewhere in the period (a real zero), else null; a day with no row at all
 * is null. cost_per_result is null for null / 0 results. A missing day is
 * never drawn as a $0 cost per result.
 */
final class MetaAdsTrendSeries
{
    private const WEEKDAY_LABEL_MAX_DAYS = 14;

    public function __construct(private readonly MetaAdsMetricQueries $metrics)
    {
    }

    /**
     * @param  string|null  $campaignUid  optional campaign filter (uid, resolved inside the account)
     * @return array{period: array<string, mixed>, currency: string, granularity: string, has_data: bool, result_type_chosen: bool, has_result_data: bool, labels: array<int, string>, tooltips: array<int, string>, series: array<string, array<int, int|float|null>>}
     */
    public function forPeriod(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $campaignUid = null): array
    {
        $entityId = null;
        $unknownEntity = false;

        if ($campaignUid !== null) {
            $entityId = MetaAdsCampaign::query()
                ->where('business_id', $account->business_id)
                ->where('meta_ads_account_id', $account->id)
                ->where('uid', $campaignUid)
                ->value('id');
            $unknownEntity = $entityId === null;
            $entityId = $entityId === null ? 0 : (int) $entityId;
        }

        $insights = $this->metrics->daily($account, MetaAdsLevel::Campaign, $period->fromDate(), $period->toDate())
            ->selectRaw('metric_date, SUM(spend_micros) AS spend_micros, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(link_clicks) AS link_clicks')
            ->groupBy('metric_date');

        if ($campaignUid !== null) {
            $insights->where('entity_id', $unknownEntity ? 0 : $entityId);
        }

        $byDate = [];
        foreach ($insights->get() as $row) {
            $byDate[CarbonImmutable::parse((string) $row->metric_date)->format('Y-m-d')] = $row;
        }

        $type = $this->metrics->results()->chosenType($account);
        $resultsByDate = [];

        if ($type !== null) {
            $query = $this->metrics->results()->daily($account, MetaAdsLevel::Campaign, $period->fromDate(), $period->toDate(), $type)
                ->selectRaw('metric_date, SUM(results) AS results')
                ->groupBy('metric_date');

            if ($campaignUid !== null) {
                $query->where('entity_id', $unknownEntity ? 0 : $entityId);
            }

            foreach ($query->get() as $row) {
                $resultsByDate[CarbonImmutable::parse((string) $row->metric_date)->format('Y-m-d')] = bcadd((string) $row->results, '0', 6);
            }
        }

        // Account-wide for the period: is a zero a real zero? (entity filter ignored on purpose.)
        $hasResultData = $type !== null && ($campaignUid === null
            ? $resultsByDate !== []
            : $this->metrics->results()->hasData($account, MetaAdsLevel::Campaign, $period->fromDate(), $period->toDate()));

        $labels = [];
        $tooltips = [];
        $series = ['spend' => [], 'spend_micros' => [], 'impressions' => [], 'clicks' => [], 'link_clicks' => [], 'results' => [], 'cost_per_result' => []];
        $weekday = $period->days() <= self::WEEKDAY_LABEL_MAX_DAYS;

        for ($day = $period->from; $day->lessThanOrEqualTo($period->to); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $row = $byDate[$key] ?? null;
            $spend = $row === null ? 0 : (int) $row->spend_micros;

            $results = null;
            if ($type !== null && $row !== null) {
                $results = $resultsByDate[$key] ?? ($hasResultData ? '0.000000' : null);
            }

            $cpr = $results === null ? null : MetaAdsMetricTotals::fromRow((object) [
                'row_count' => 1, 'day_count' => 1, 'spend_micros' => $spend, 'impressions' => 0, 'clicks' => 0,
                'link_clicks' => null, 'last_date' => $key, 'results' => $results, 'result_row_count' => 1,
            ], true)->costPerResultMicros();

            $labels[] = $day->format($weekday ? 'D j' : 'M j');
            $tooltips[] = $day->format('D, M j, Y');
            $series['spend'][] = (float) MetaAdsMoney::microsToDecimalString($spend, 2);
            $series['spend_micros'][] = $spend;
            $series['impressions'][] = $row === null ? 0 : (int) $row->impressions;
            $series['clicks'][] = $row === null ? 0 : (int) $row->clicks;
            $series['link_clicks'][] = $row === null || $row->link_clicks === null ? null : (int) $row->link_clicks;
            $series['results'][] = $results === null ? null : (float) $results;
            $series['cost_per_result'][] = $cpr === null ? null : (float) MetaAdsMoney::microsToDecimalString($cpr, 2);
        }

        return [
            'period' => $period->toArray(),
            'currency' => (string) $account->currency_code,
            'granularity' => 'day',
            'has_data' => $byDate !== [],
            'result_type_chosen' => $type !== null,
            'has_result_data' => $hasResultData,
            'labels' => $labels,
            'tooltips' => $tooltips,
            'series' => $series,
        ];
    }
}
