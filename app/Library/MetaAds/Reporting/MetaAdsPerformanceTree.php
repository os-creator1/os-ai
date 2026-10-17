<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsPagedResult;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Enums\MetaAds\MetaAdsLevel;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Facades\DB;

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

    public function __construct(
        private readonly MetaAdsCampaignReader $campaigns,
        private readonly MetaAdsAdSetReader $adSets,
        private readonly MetaAdsAdReader $ads,
        private readonly MetaAdsMetricQueries $metrics,
        /** Safeguards on the child reads; the defaults are the constants above (a test may lower them). */
        private readonly int $adSetLimit = self::AD_SET_LIMIT,
        private readonly int $adLimit = self::AD_LIMIT,
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

        $adSetRead = $this->adSets->forCampaigns($account, $period, array_keys($campaignUidById), $this->adSetLimit);
        $adSetsByCampaign = [];
        $adSetIds = [];

        foreach ($adSetRead['items'] as $adSet) {
            $adSetsByCampaign[$adSet->campaignUid][] = $adSet;
            $adSetIds[] = $adSet->localId;
        }

        $adRead = $this->ads->forAdSets($account, $period, $adSetIds, $this->adLimit);
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
     * Exact totals row over the campaign level of the FILTERED campaigns (the
     * same set the table pages through, not just the visible page), so ad sets
     * and ads are never added on top. Three bounded aggregate queries
     * regardless of how many campaigns match; no row is loaded.
     *
     * Budget is the sum of the daily budgets set in Meta Ads on the matching
     * ACTIVE campaigns (paused budgets are not being spent; lifetime budgets
     * are not a per-day figure and are left out).
     *
     * @return array{spendMicros: ?int, impressions: ?int, linkClicks: ?int, results: ?string, costPerResultMicros: ?int, dailyBudgetMinor: ?int, campaignCount: int}
     */
    private function totals(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $status, int $matching): array
    {
        $empty = ['spendMicros' => null, 'impressions' => null, 'linkClicks' => null, 'results' => null, 'costPerResultMicros' => null, 'dailyBudgetMinor' => null, 'campaignCount' => $matching];

        if ($matching === 0) {
            return $empty;
        }

        $statusValue = $status === null ? null : strtoupper(trim($status));
        $statusValue = in_array($statusValue, ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'], true) ? $statusValue : null;
        $campaignIds = function ($query) use ($account, $statusValue) {
            $query->select('id')->from('meta_ads_campaigns')
                ->where('business_id', $account->business_id)
                ->where('meta_ads_account_id', $account->id);

            if ($statusValue !== null) {
                $query->where('status', $statusValue);
            }
        };

        $from = $period->fromDate();
        $to = $period->toDate();
        $insight = $this->metrics->daily($account, MetaAdsLevel::Campaign, $from, $to)
            ->whereIn('entity_id', $campaignIds)
            ->selectRaw('SUM(spend_micros) AS spend_micros, SUM(impressions) AS impressions, SUM(link_clicks) AS link_clicks, COUNT(*) AS row_count')
            ->first();

        $spend = $insight === null || (int) $insight->row_count === 0 || $insight->spend_micros === null ? null : (int) $insight->spend_micros;
        $results = null;
        $type = $this->metrics->results()->chosenType($account);

        if ($type !== null && $insight !== null && (int) $insight->row_count > 0) {
            $row = $this->metrics->results()->daily($account, MetaAdsLevel::Campaign, $from, $to, $type)
                ->whereIn('entity_id', $campaignIds)
                ->selectRaw('SUM(results) AS results, COUNT(*) AS result_row_count')
                ->first();

            if ($row !== null && (int) $row->result_row_count > 0 && $row->results !== null) {
                $results = bcadd((string) $row->results, '0', 6);
            } elseif ($this->metrics->resultDataPresent($account, MetaAdsLevel::Campaign, $from, $to)) {
                $results = '0.000000';   // Meta reported results for other campaigns and none here: a real zero
            }
        }

        $cost = MetaAdsMetricTotals::costPerResult($spend, $results);

        $budget = DB::table('meta_ads_campaigns')
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->where('status', 'ACTIVE')
            ->whereNotNull('daily_budget_minor');

        if ($statusValue !== null && $statusValue !== 'ACTIVE') {
            $budget->whereRaw('1 = 0');
        }

        // SUM and COUNT together: a stored zero budget (sum 0 over >= 1 rows) is a real zero, no rows at all is unknown (null).
        $budgetRow = $budget->selectRaw('SUM(daily_budget_minor) AS budget_sum, COUNT(*) AS budget_rows')->first();
        $dailyBudget = $budgetRow === null || (int) $budgetRow->budget_rows === 0 ? null : (int) $budgetRow->budget_sum;

        return [
            'spendMicros' => $spend,
            'impressions' => $insight === null || (int) $insight->row_count === 0 || $insight->impressions === null ? null : (int) $insight->impressions,
            'linkClicks' => $insight === null || (int) $insight->row_count === 0 || $insight->link_clicks === null ? null : (int) $insight->link_clicks,
            'results' => MetaAdsMetricTotals::trim($results),
            'costPerResultMicros' => $cost,
            'dailyBudgetMinor' => $dailyBudget,
            'campaignCount' => $matching,
        ];
    }
}
