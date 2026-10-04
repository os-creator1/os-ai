<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 contract §4 / §9 — the Campaigns table and the
 * campaign detail page, from cached rows only.
 *
 * Campaign rows are addressed by (campaign uid, Business, selected account);
 * an id from another Business or account simply does not resolve (null).
 * Per-campaign metrics are the campaign-level daily rows for the period,
 * left-joined so a campaign with no rows shows null metrics, not zeros.
 */
final class GoogleAdsCampaignReader
{
    public const SORTS = ['name', 'status', 'budget', ...GoogleAdsMetricQueries::METRIC_SORTS];

    /** Row cap of the detail sections (keywords / search terms / ad groups). */
    public const DETAIL_LIMIT = 25;

    public function __construct(
        private readonly GoogleAdsMetricQueries $metrics,
        private readonly GoogleAdsTrendSeries $trend,
        private readonly GoogleAdsKeywordReader $keywords,
        private readonly GoogleAdsSearchTermReader $searchTerms,
    ) {
    }

    /**
     * @param  string|null  $status  ENABLED|PAUSED|REMOVED (case-insensitive); anything else = all
     * @return GoogleAdsPagedResult<GoogleAdsCampaignRow>
     */
    public function page(
        GoogleAdsAccount $account,
        GoogleAdsPeriod $period,
        ?string $status = null,
        string $sort = 'spend',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 25,
    ): GoogleAdsPagedResult {
        [$page, $perPage, $offset] = GoogleAdsMetricQueries::window($page, $perPage);
        $query = $this->query($account, $period, $status);
        $total = (clone $query)->count();

        $this->applySort($query, $sort, $direction);
        $rows = $query->offset($offset)->limit($perPage)->get();

        return new GoogleAdsPagedResult($rows->map(fn (object $row) => $this->hydrate($row))->all(), $total, $page, $perPage);
    }

    /**
     * Every matching campaign (bounded by $limit), spend-descending: the
     * Budget page's per-campaign list.
     *
     * @return array<int, GoogleAdsCampaignRow>
     */
    public function list(GoogleAdsAccount $account, GoogleAdsPeriod $period, ?string $status = null, int $limit = 200): array
    {
        return $this->page($account, $period, $status, 'spend', 'desc', 1, $limit)->items;
    }

    /**
     * uid => name of the account's campaigns (no metrics): the filter dropdown
     * and the whitelist for a `campaign` query parameter.
     *
     * @return array<string, string>
     */
    public function options(GoogleAdsAccount $account, int $limit = 200): array
    {
        return GoogleAdsCampaign::query()
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->orderBy('name')->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('name', 'uid')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    public function find(GoogleAdsAccount $account, string $campaignUid, GoogleAdsPeriod $period): ?GoogleAdsCampaignRow
    {
        $row = $this->query($account, $period, null)->where('c.uid', $campaignUid)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /** Campaign detail, or null when the uid is not in this Business's account. */
    public function detail(GoogleAdsAccount $account, string $campaignUid, GoogleAdsPeriod $period): ?GoogleAdsCampaignDetail
    {
        $campaign = $this->find($account, $campaignUid, $period);

        if ($campaign === null) {
            return null;
        }

        $internal = $this->internalIds($account, $campaignUid);

        return new GoogleAdsCampaignDetail(
            campaign: $campaign,
            period: $period,
            trend: $this->trend->forPeriod($account, $period, $campaignUid),
            adGroups: $this->adGroups($account, $internal['id'], $period),
            keywords: $this->keywords->page($account, $period, $campaignUid, null, 'spend', 'desc', 1, self::DETAIL_LIMIT),
            searchTerms: $this->searchTerms->page($account, $period, null, $campaignUid, 'spend', 'desc', 1, 10),
            budget: $this->budgetFacts($campaign, $period),
        );
    }

    /**
     * Campaign DAILY budget facts, kept apart from the Business MONTHLY
     * target: budget, average daily spend over days with data, and the
     * average as a fraction of the daily budget (null without a budget).
     *
     * @return array{daily_budget_micros: ?int, budget_shared: ?bool, spend_micros: ?int, days_with_data: int, average_daily_spend_micros: ?int, budget_utilisation: ?float}
     */
    public function budgetFacts(GoogleAdsCampaignRow $campaign, GoogleAdsPeriod $period): array
    {
        $totals = $campaign->totals;
        $average = $totals->spendMicros !== null && $totals->dayCount > 0
            ? (int) bcadd(bcdiv((string) $totals->spendMicros, (string) $totals->dayCount, 6), '0.5', 0)
            : null;
        $utilisation = $average !== null && $campaign->dailyBudgetMicros !== null && $campaign->dailyBudgetMicros > 0
            ? (float) bcdiv((string) $average, (string) $campaign->dailyBudgetMicros, 6)
            : null;

        return [
            'daily_budget_micros' => $campaign->dailyBudgetMicros,
            'budget_shared' => $campaign->budgetShared,
            'spend_micros' => $totals->spendMicros,
            'days_with_data' => $totals->dayCount,
            'average_daily_spend_micros' => $average,
            'budget_utilisation' => $utilisation,
        ];
    }

    /**
     * Ad-group sums are the sum of that ad group's KEYWORD-level daily rows
     * (the sync stores no ad-group level), so they can understate spend that
     * Google did not attribute to a keyword.
     *
     * @return array<int, array{name: string, status: GoogleAdsEntityStatus, totals: GoogleAdsMetricTotals}>
     */
    private function adGroups(GoogleAdsAccount $account, int $campaignId, GoogleAdsPeriod $period): array
    {
        $groups = DB::table('google_ads_ad_groups')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('google_ads_campaign_id', $campaignId)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'status']);

        if ($groups->isEmpty()) {
            return [];
        }

        $sums = DB::table('google_ads_daily_metrics as d')
            ->join('google_ads_keywords as k', function ($join) {
                $join->on('k.external_criterion_id', '=', 'd.entity_key')
                    ->on('k.google_ads_account_id', '=', 'd.google_ads_account_id');
            })
            ->where('d.business_id', $account->business_id)
            ->where('d.google_ads_account_id', $account->id)
            ->where('d.level', GoogleAdsMetricLevel::Keyword->value)
            ->whereBetween('d.metric_date', [$period->fromDate(), $period->toDate()])
            ->where('k.business_id', $account->business_id)
            ->where('k.google_ads_campaign_id', $campaignId)
            ->where('k.is_negative', false)
            ->whereNotNull('k.google_ads_ad_group_id')
            ->groupBy('k.google_ads_ad_group_id')
            ->selectRaw('k.google_ads_ad_group_id AS ad_group_id, ' . GoogleAdsMetricQueries::AGGREGATE_COLUMNS)
            ->get()
            ->keyBy('ad_group_id');

        return $groups->map(fn (object $group): array => [
            'name' => (string) $group->name,
            'status' => GoogleAdsEntityStatus::fromProvider($group->status),
            'totals' => GoogleAdsMetricTotals::fromRow($sums->get($group->id)),
        ])->sortByDesc(fn (array $g): int => (int) $g['totals']->spendMicros)->values()->all();
    }

    /** @return array{id: int, external_campaign_id: string} */
    private function internalIds(GoogleAdsAccount $account, string $campaignUid): array
    {
        $campaign = GoogleAdsCampaign::query()
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('uid', $campaignUid)
            ->first(['id', 'external_campaign_id']);

        return ['id' => (int) $campaign?->id, 'external_campaign_id' => (string) $campaign?->external_campaign_id];
    }

    private function query(GoogleAdsAccount $account, GoogleAdsPeriod $period, ?string $status): Builder
    {
        $query = DB::table('google_ads_campaigns as c')
            ->leftJoinSub(
                $this->metrics->perEntity($account, GoogleAdsMetricLevel::Campaign, $period->fromDate(), $period->toDate()),
                'm',
                'm.entity_key',
                '=',
                'c.external_campaign_id',
            )
            ->where('c.business_id', $account->business_id)
            ->where('c.google_ads_account_id', $account->id)
            ->selectRaw('c.id, c.uid, c.name, c.status, c.channel_type, c.budget_amount_micros, c.budget_shared, c.external_campaign_id, '
                . 'm.spend_micros, m.clicks, m.impressions, m.conversions, m.conversions_value, m.row_count, m.day_count, m.last_date');

        $enum = $status === null ? null : GoogleAdsEntityStatus::tryFrom(strtoupper(trim($status)));

        if ($enum !== null) {
            $query->where('c.status', $enum->value);
        }

        return $query;
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = GoogleAdsMetricQueries::direction($direction);
        $expression = match ($sort) {
            'name' => 'c.name',
            'status' => 'c.status',
            'budget' => 'c.budget_amount_micros',
            default => GoogleAdsMetricQueries::metricSortExpression($sort) ?? 'm.spend_micros',
        };

        $query->orderByRaw("({$expression}) IS NULL ASC")
            ->orderByRaw("({$expression}) {$direction}")
            ->orderBy('c.id');
    }

    private function hydrate(object $row): GoogleAdsCampaignRow
    {
        return new GoogleAdsCampaignRow(
            uid: (string) $row->uid,
            name: (string) $row->name,
            status: GoogleAdsEntityStatus::fromProvider($row->status),
            channelType: $row->channel_type,
            dailyBudgetMicros: $row->budget_amount_micros === null ? null : (int) $row->budget_amount_micros,
            budgetShared: $row->budget_shared === null ? null : (bool) $row->budget_shared,
            totals: GoogleAdsMetricTotals::fromRow($row),
            internal: ['external_campaign_id' => (string) $row->external_campaign_id],
        );
    }
}
