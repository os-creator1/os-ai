<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsPagedResult;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\MetaAdsAccount;

/**
 * Meta Ads Module V1 — the Performance table's Campaign -> Ad set -> Ad tree,
 * from cached rows only (nothing here calls Meta).
 *
 * The campaign page (filters, sort, paging) comes from MetaAdsCampaignReader
 * unchanged. Children are then read for THAT PAGE ONLY, one query per level
 * (no N+1): ad sets of the page's campaigns, ads of those ad sets. Every row
 * carries its own level's cached metrics; a parent is never the sum of its
 * children, so nothing is double counted. Missing metrics stay null.
 *
 * Children are bounded. When a bound cuts rows off, `adSetsTruncated` /
 * `adsTruncated` say so and the view must not claim a parent is empty.
 */
final class MetaAdsPerformanceTree
{
    public const AD_SET_LIMIT = 500;

    public const AD_LIMIT = 1000;

    /** Campaigns summed for the totals row; beyond this the row is labelled partial. */
    public const TOTALS_LIMIT = 200;

    public function __construct(
        private readonly MetaAdsCampaignReader $campaigns,
        private readonly MetaAdsAdSetReader $adSets,
        private readonly MetaAdsAdReader $ads,
    ) {
    }

    /**
     * @return array{
     *   result: GoogleAdsPagedResult<MetaAdsCampaignRow>,
     *   adSetsByCampaign: array<string, array<int, MetaAdsAdSetRow>>,
     *   adsByAdSet: array<string, array<int, MetaAdsAdRow>>,
     *   adSetsTruncated: bool,
     *   adsTruncated: bool,
     *   totals: array<string, mixed>
     * }
     */
    public function page(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $status, string $sort, string $direction, int $page): array
    {
        $result = $this->campaigns->page($account, $period, $status, $sort, $direction, $page);

        $campaignUidById = [];

        foreach ($result->items as $campaign) {
            $campaignUidById[$campaign->localId] = $campaign->uid;
        }

        $adSetRead = $this->adSets->forCampaigns($account, $period, array_keys($campaignUidById), self::AD_SET_LIMIT);
        $adSetsByCampaign = [];
        $adSetIds = [];

        foreach ($adSetRead['items'] as $adSet) {
            $adSetsByCampaign[$adSet->campaignUid][] = $adSet;
            $adSetIds[] = $adSet->localId;
        }

        $adRead = $this->ads->forAdSets($account, $period, $adSetIds, self::AD_LIMIT);
        $adsByAdSet = [];

        foreach ($adRead['items'] as $ad) {
            $adsByAdSet[$ad->adSetUid][] = $ad;
        }

        return [
            'result' => $result,
            'adSetsByCampaign' => $adSetsByCampaign,
            'adsByAdSet' => $adsByAdSet,
            'adSetsTruncated' => $adSetRead['total'] > count($adSetRead['items']),
            'adsTruncated' => $adRead['total'] > count($adRead['items']),
            'totals' => $this->totals($account, $period, $status, $result->total),
        ];
    }

    /**
     * Totals row over the campaign level of the FILTERED campaigns only (so
     * ad sets and ads are never added on top). Budget sums the daily budgets
     * set in Meta Ads; lifetime budgets are not a per-day figure and are left
     * out. `partial` is true when more campaigns match than are summed.
     *
     * @return array{spendMicros: ?int, impressions: ?int, linkClicks: ?int, results: ?string, costPerResultMicros: ?int, dailyBudgetMinor: ?int, partial: bool, campaignCount: int}
     */
    private function totals(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $status, int $matching): array
    {
        $rows = $matching === 0 ? [] : $this->campaigns->list($account, $period, $status, self::TOTALS_LIMIT);

        $spend = $impressions = $linkClicks = $budget = null;
        $results = null;

        foreach ($rows as $row) {
            $spend = self::add($spend, $row->totals->spendMicros);
            $impressions = self::add($impressions, $row->totals->impressions);
            $linkClicks = self::add($linkClicks, $row->totals->linkClicks);
            $budget = self::add($budget, $row->dailyBudgetMinor);

            if ($row->totals->results !== null) {
                $results = bcadd($results ?? '0', $row->totals->results, 6);
            }
        }

        $cost = null;

        if ($spend !== null && $results !== null && bccomp($results, '0', 6) > 0) {
            $cost = (int) bcadd(bcdiv((string) $spend, $results, 6), '0.5', 0);
        }

        return [
            'spendMicros' => $spend,
            'impressions' => $impressions,
            'linkClicks' => $linkClicks,
            'results' => MetaAdsMetricTotals::trim($results),
            'costPerResultMicros' => $cost,
            'dailyBudgetMinor' => $budget,
            'partial' => $matching > count($rows),
            'campaignCount' => $matching,
        ];
    }

    private static function add(?int $sum, ?int $value): ?int
    {
        return $value === null ? $sum : ($sum ?? 0) + $value;
    }
}
