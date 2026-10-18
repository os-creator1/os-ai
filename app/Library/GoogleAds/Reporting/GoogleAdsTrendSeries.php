<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\GoogleAdsMoney;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 contract §9 — the daily chart payload, from cached
 * CAMPAIGN-level rows only.
 *
 * Shape is compatible with the Analytics `/series` JSON so the page can reuse
 * the theme chart helpers: `labels` (short axis labels, "Mon 7" up to 14
 * days else "Oct 4", same formats as AnalyticsChartBuckets), `tooltips`
 * (exact dates) and `series` (name => one value per label). Every period is
 * at most 31 days, so the point-per-day granularity always applies.
 *
 * Series:
 *   spend         major units of the account currency (float, for the axis)
 *   spend_micros  exact integer micros (for tooltips / tables)
 *   clicks        int
 *   conversions   float | null
 *   cpl           float | null   (major units)
 *
 * Gap rules: spend / spend_micros / clicks are ZERO-FILLED for days without
 * a row (visual continuity only). conversions and cpl are NULL for days with
 * no row, with null conversions, or (cpl) with 0 conversions — a missing day
 * is never drawn as zero conversions or a $0 CPL. `has_conversion_data` is
 * true only when at least one day has non-null conversions.
 */
final class GoogleAdsTrendSeries
{
    private const WEEKDAY_LABEL_MAX_DAYS = 14;

    public function __construct(private readonly GoogleAdsMetricQueries $metrics)
    {
    }

    /**
     * @param  string|null  $campaignUid  optional campaign filter (uid, resolved inside the account)
     * @return array{period: array<string, mixed>, currency: string, granularity: string, has_data: bool, has_conversion_data: bool, labels: array<int, string>, tooltips: array<int, string>, series: array<string, array<int, int|float|null>>}
     */
    public function forPeriod(GoogleAdsAccount $account, GoogleAdsPeriod $period, ?string $campaignUid = null): array
    {
        $query = $this->metrics->daily($account, GoogleAdsMetricLevel::Campaign, $period->fromDate(), $period->toDate())
            ->selectRaw('metric_date, SUM(cost_micros) AS spend_micros, SUM(clicks) AS clicks, SUM(conversions) AS conversions')
            ->groupBy('metric_date');

        if ($campaignUid !== null) {
            $external = GoogleAdsCampaign::query()
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id)
                ->where('uid', $campaignUid)
                ->value('external_campaign_id');

            // Unknown uid in this account => an empty (but well-formed) series.
            $query->where('entity_key', $external === null ? '' : (string) $external);
        }

        $byDate = [];
        foreach ($query->get() as $row) {
            $byDate[CarbonImmutable::parse((string) $row->metric_date)->format('Y-m-d')] = $row;
        }

        $labels = [];
        $tooltips = [];
        $series = ['spend' => [], 'spend_micros' => [], 'clicks' => [], 'conversions' => [], 'cpl' => []];
        $weekday = $period->days() <= self::WEEKDAY_LABEL_MAX_DAYS;
        $hasConversionData = false;

        for ($day = $period->from; $day->lessThanOrEqualTo($period->to); $day = $day->addDay()) {
            $row = $byDate[$day->format('Y-m-d')] ?? null;
            $spend = $row === null ? 0 : (int) $row->spend_micros;
            $conversions = $row === null || $row->conversions === null ? null : bcadd((string) $row->conversions, '0', 6);
            $cpl = GoogleAdsMoney::cpl($row === null ? null : $spend, $conversions);

            $labels[] = $day->format($weekday ? 'D j' : 'M j');
            $tooltips[] = $day->format('D, M j, Y');
            $series['spend'][] = (float) GoogleAdsMoney::microsToDecimalString($spend, 2);
            $series['spend_micros'][] = $spend;
            $series['clicks'][] = $row === null ? 0 : (int) $row->clicks;
            $series['conversions'][] = $conversions === null ? null : (float) $conversions;
            $series['cpl'][] = $cpl === null ? null : (float) GoogleAdsMoney::microsToDecimalString($cpl, 2);

            $hasConversionData = $hasConversionData || $conversions !== null;
        }

        return [
            'period' => $period->toArray(),
            'currency' => (string) $account->currency_code,
            'granularity' => 'day',
            'has_data' => $byDate !== [],
            'has_conversion_data' => $hasConversionData,
            'labels' => $labels,
            'tooltips' => $tooltips,
            'series' => $series,
        ];
    }
}
