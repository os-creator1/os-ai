<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPagedResult;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAdSet;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §5 — the Ad sets table, from cached rows
 * only. Addressed by (ad set uid, Business, selected account): a foreign or
 * unknown uid simply does not resolve (null). Metrics are the ad-set-level
 * daily rows of the period, left-joined through ONE aggregate sub-select (no
 * N+1) so an ad set without rows shows null metrics, not zeros.
 *
 * A page costs 3 queries (count, rows, result-presence) regardless of size.
 */
final class MetaAdsAdSetReader
{
    use MetaAdsEntityReaderSupport;

    public const SORTS = ['name', 'status', 'campaign', 'budget', 'frequency', ...MetaAdsMetricQueries::METRIC_SORTS];

    public function __construct(private readonly MetaAdsMetricQueries $metrics)
    {
    }

    /**
     * @param  string|null  $status  ACTIVE|PAUSED|DELETED|ARCHIVED (case-insensitive); anything else = all
     * @return GoogleAdsPagedResult<MetaAdsAdSetRow>
     */
    public function page(
        MetaAdsAccount $account,
        GoogleAdsPeriod $period,
        ?string $status = null,
        ?string $campaignUid = null,
        string $sort = 'spend',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 25,
    ): GoogleAdsPagedResult {
        [$page, $perPage, $offset] = MetaAdsMetricQueries::window($page, $perPage);
        $base = $this->base($account, $status, $campaignUid);
        $total = (clone $base)->count();

        $chosen = $this->select($base, $account, $period);
        $this->applySort($base, $sort, $direction, $chosen, [
            'name' => 's.name',
            'status' => 's.status',
            'campaign' => 'c.name',
            'budget' => 'COALESCE(s.daily_budget_minor, s.lifetime_budget_minor)',
            'frequency' => 's.frequency_7d',
        ], 's.id');

        $rows = $base->offset($offset)->limit($perPage)->get();
        $present = $chosen && $rows->isNotEmpty() && $this->resultPresent($account, $period, MetaAdsLevel::AdSet);

        return new GoogleAdsPagedResult(
            $rows->map(fn (object $row) => $this->hydrate($row, $account, $chosen, $present))->all(),
            $total,
            $page,
            $perPage,
        );
    }

    /** @return array<int, MetaAdsAdSetRow> */
    public function list(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $status = null, ?string $campaignUid = null, int $limit = 200): array
    {
        return $this->page($account, $period, $status, $campaignUid, 'spend', 'desc', 1, $limit)->items;
    }

    /**
     * uid => name of the account's ad sets (no metrics): filter dropdown and
     * whitelist for an `ad_set` query parameter.
     *
     * @return array<string, string>
     */
    public function options(MetaAdsAccount $account, int $limit = 200): array
    {
        return MetaAdsAdSet::query()
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->orderBy('name')->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('name', 'uid')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    public function find(MetaAdsAccount $account, string $adSetUid, GoogleAdsPeriod $period): ?MetaAdsAdSetRow
    {
        $query = $this->base($account, null, null)->where('s.uid', $adSetUid);
        $chosen = $this->select($query, $account, $period);
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $account, $chosen, $chosen && $this->resultPresent($account, $period, MetaAdsLevel::AdSet));
    }

    private function base(MetaAdsAccount $account, ?string $status, ?string $campaignUid): Builder
    {
        $query = DB::table('meta_ads_ad_sets as s')
            ->join('meta_ads_campaigns as c', function ($join) {
                $join->on('c.id', '=', 's.meta_ads_campaign_id')
                    ->on('c.meta_ads_account_id', '=', 's.meta_ads_account_id');
            })
            ->where('s.business_id', $account->business_id)
            ->where('s.meta_ads_account_id', $account->id);

        $enum = $this->statusValue($status);

        if ($enum !== null) {
            $query->where('s.status', $enum);
        }

        if ($campaignUid !== null) {
            $query->where('c.uid', $campaignUid);
        }

        return $query;
    }

    private function select(Builder $query, MetaAdsAccount $account, GoogleAdsPeriod $period): bool
    {
        $query->selectRaw('s.id, s.uid, s.name, s.status, s.effective_status, s.daily_budget_minor, s.lifetime_budget_minor, '
            . 's.optimization_goal, s.bid_strategy, s.targeting_summary, s.reach_7d, s.frequency_7d, s.frequency_window_end, '
            . 'c.uid AS campaign_uid, c.name AS campaign_name');

        return $this->metrics->joinEntityMetrics($query, $account, MetaAdsLevel::AdSet, $period->fromDate(), $period->toDate(), 's.id');
    }

    private function hydrate(object $row, MetaAdsAccount $account, bool $chosen, bool $present): MetaAdsAdSetRow
    {
        return new MetaAdsAdSetRow(
            uid: (string) $row->uid,
            name: (string) $row->name,
            status: (string) $row->status,
            effectiveStatus: $row->effective_status === null ? null : (string) $row->effective_status,
            campaignUid: (string) $row->campaign_uid,
            campaignName: (string) $row->campaign_name,
            dailyBudgetMinor: $row->daily_budget_minor === null ? null : (int) $row->daily_budget_minor,
            lifetimeBudgetMinor: $row->lifetime_budget_minor === null ? null : (int) $row->lifetime_budget_minor,
            optimizationGoal: $row->optimization_goal === null ? null : (string) $row->optimization_goal,
            bidStrategy: $row->bid_strategy === null ? null : (string) $row->bid_strategy,
            targetingSummary: $row->targeting_summary === null ? null : (string) $row->targeting_summary,
            reach7d: $row->reach_7d === null ? null : (int) $row->reach_7d,
            frequency7d: $row->frequency_7d === null ? null : (string) $row->frequency_7d,
            frequencyWindowEnd: $this->time($row->frequency_window_end),
            currencyCode: (string) $account->currency_code,
            totals: MetaAdsMetricTotals::fromRow($row, $chosen, $present),
            localId: (int) $row->id,
        );
    }
}
