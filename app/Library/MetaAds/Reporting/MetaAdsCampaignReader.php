<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPagedResult;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsMoney;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsCampaign;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §5 — the Campaigns table and the campaign
 * detail page, from cached rows only.
 *
 * Campaign rows are addressed by (campaign uid, Business, selected account);
 * an id from another Business or account simply does not resolve (null).
 * Per-campaign metrics are the campaign-level daily rows for the period
 * left-joined through ONE aggregate sub-select (no N+1), so a campaign with no
 * rows shows null metrics, not zeros. A page costs 3 queries.
 */
final class MetaAdsCampaignReader
{
    use MetaAdsEntityReaderSupport;

    public const SORTS = ['name', 'status', 'budget', ...MetaAdsMetricQueries::METRIC_SORTS];

    /** Row cap of the detail sections (ad sets / ads). */
    public const DETAIL_LIMIT = 25;

    public function __construct(
        private readonly MetaAdsMetricQueries $metrics,
        private readonly MetaAdsTrendSeries $trend,
        private readonly MetaAdsAdSetReader $adSets,
        private readonly MetaAdsAdReader $ads,
    ) {
    }

    /**
     * @param  string|null  $status  ACTIVE|PAUSED|DELETED|ARCHIVED (case-insensitive); anything else = all
     * @return GoogleAdsPagedResult<MetaAdsCampaignRow>
     */
    public function page(
        MetaAdsAccount $account,
        GoogleAdsPeriod $period,
        ?string $status = null,
        string $sort = 'spend',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 25,
    ): GoogleAdsPagedResult {
        [$page, $perPage, $offset] = MetaAdsMetricQueries::window($page, $perPage);
        $base = $this->base($account, $status);
        $total = (clone $base)->count();

        $chosen = $this->select($base, $account, $period);
        $this->applySort($base, $sort, $direction, $chosen, [
            'name' => 'c.name',
            'status' => 'c.status',
            'budget' => 'COALESCE(c.daily_budget_minor, c.lifetime_budget_minor)',
        ], 'c.id');

        $rows = $base->offset($offset)->limit($perPage)->get();
        $present = $chosen && $rows->isNotEmpty() && $this->resultPresent($account, $period, MetaAdsLevel::Campaign);

        return new GoogleAdsPagedResult(
            $rows->map(fn (object $row) => $this->hydrate($row, $account, $chosen, $present))->all(),
            $total,
            $page,
            $perPage,
        );
    }

    /**
     * Every matching campaign (bounded by $limit), spend-descending: the
     * Budget page's per-campaign list and the recommendation reader's input.
     *
     * @return array<int, MetaAdsCampaignRow>
     */
    public function list(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $status = null, int $limit = 200): array
    {
        return $this->page($account, $period, $status, 'spend', 'desc', 1, $limit)->items;
    }

    /**
     * uid => name of the account's campaigns (no metrics): the filter dropdown
     * and the whitelist for a `campaign` query parameter.
     *
     * @return array<string, string>
     */
    public function options(MetaAdsAccount $account, int $limit = 200): array
    {
        return MetaAdsCampaign::query()
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->orderBy('name')->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('name', 'uid')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    public function find(MetaAdsAccount $account, string $campaignUid, GoogleAdsPeriod $period): ?MetaAdsCampaignRow
    {
        $query = $this->base($account, null)->where('c.uid', $campaignUid);
        $chosen = $this->select($query, $account, $period);
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $account, $chosen, $chosen && $this->resultPresent($account, $period, MetaAdsLevel::Campaign));
    }

    /** Campaign detail, or null when the uid is not in this Business's account. */
    public function detail(MetaAdsAccount $account, string $campaignUid, GoogleAdsPeriod $period): ?MetaAdsCampaignDetail
    {
        $campaign = $this->find($account, $campaignUid, $period);

        if ($campaign === null) {
            return null;
        }

        $adSets = $this->adSets->page($account, $period, null, $campaignUid, 'spend', 'desc', 1, self::DETAIL_LIMIT);
        $ads = $this->ads->page($account, $period, null, $campaignUid, null, 'spend', 'desc', 1, self::DETAIL_LIMIT);

        return new MetaAdsCampaignDetail(
            campaign: $campaign,
            period: $period,
            trend: $this->trend->forPeriod($account, $period, $campaignUid),
            adSets: $adSets->items,
            adSetsTotal: $adSets->total,
            ads: $ads->items,
            adsTotal: $ads->total,
            budget: $this->budgetFacts($campaign),
        );
    }

    /**
     * Campaign DAILY / LIFETIME budget facts, kept apart from the Business
     * MONTHLY target. Meta budgets arrive as MINOR units: micros are derived
     * through MetaAdsMoney (CurrencyExponent-aware, so JPY is whole units) and
     * are null for an unlisted currency. Daily and lifetime budgets are
     * mutually exclusive on Meta; utilisation (average daily spend over days
     * with data / daily budget) only exists for a daily budget.
     *
     * @return array{currency_code: string, budget_type: ?string, daily_budget_minor: ?int, lifetime_budget_minor: ?int, budget_remaining_minor: ?int, daily_budget_micros: ?int, lifetime_budget_micros: ?int, spend_micros: ?int, days_with_data: int, average_daily_spend_micros: ?int, budget_utilisation: ?float}
     */
    public function budgetFacts(MetaAdsCampaignRow $campaign): array
    {
        $totals = $campaign->totals;
        $average = $totals->spendMicros !== null && $totals->dayCount > 0
            ? (int) bcadd(bcdiv((string) $totals->spendMicros, (string) $totals->dayCount, 6), '0.5', 0)
            : null;
        $dailyMicros = $this->minorToMicros($campaign->dailyBudgetMinor, $campaign->currencyCode);
        $lifetimeMicros = $this->minorToMicros($campaign->lifetimeBudgetMinor, $campaign->currencyCode);
        $utilisation = $average !== null && $dailyMicros !== null && $dailyMicros > 0
            ? (float) bcdiv((string) $average, (string) $dailyMicros, 6)
            : null;

        return [
            'currency_code' => $campaign->currencyCode,
            'budget_type' => $campaign->dailyBudgetMinor !== null ? 'daily' : ($campaign->lifetimeBudgetMinor !== null ? 'lifetime' : null),
            'daily_budget_minor' => $campaign->dailyBudgetMinor,
            'lifetime_budget_minor' => $campaign->lifetimeBudgetMinor,
            'budget_remaining_minor' => $campaign->budgetRemainingMinor,
            'daily_budget_micros' => $dailyMicros,
            'lifetime_budget_micros' => $lifetimeMicros,
            'spend_micros' => $totals->spendMicros,
            'days_with_data' => $totals->dayCount,
            'average_daily_spend_micros' => $average,
            'budget_utilisation' => $utilisation,
        ];
    }

    private function minorToMicros(?int $minor, string $currency): ?int
    {
        if ($minor === null) {
            return null;
        }

        try {
            return MetaAdsMoney::minorToMicros($minor, $currency);
        } catch (\Throwable) {
            return null;
        }
    }

    private function base(MetaAdsAccount $account, ?string $status): Builder
    {
        $query = DB::table('meta_ads_campaigns as c')
            ->where('c.business_id', $account->business_id)
            ->where('c.meta_ads_account_id', $account->id);

        $enum = $this->statusValue($status);

        if ($enum !== null) {
            $query->where('c.status', $enum);
        }

        return $query;
    }

    private function select(Builder $query, MetaAdsAccount $account, GoogleAdsPeriod $period): bool
    {
        $query->selectRaw('c.id, c.uid, c.name, c.status, c.effective_status, c.objective, c.daily_budget_minor, '
            . 'c.lifetime_budget_minor, c.budget_remaining_minor, c.start_time, c.stop_time');

        return $this->metrics->joinEntityMetrics($query, $account, MetaAdsLevel::Campaign, $period->fromDate(), $period->toDate(), 'c.id');
    }

    private function hydrate(object $row, MetaAdsAccount $account, bool $chosen, bool $present): MetaAdsCampaignRow
    {
        return new MetaAdsCampaignRow(
            uid: (string) $row->uid,
            name: (string) $row->name,
            status: (string) $row->status,
            effectiveStatus: $row->effective_status === null ? null : (string) $row->effective_status,
            objective: $row->objective === null ? null : (string) $row->objective,
            dailyBudgetMinor: $row->daily_budget_minor === null ? null : (int) $row->daily_budget_minor,
            lifetimeBudgetMinor: $row->lifetime_budget_minor === null ? null : (int) $row->lifetime_budget_minor,
            budgetRemainingMinor: $row->budget_remaining_minor === null ? null : (int) $row->budget_remaining_minor,
            startTime: $this->time($row->start_time),
            stopTime: $this->time($row->stop_time),
            currencyCode: (string) $account->currency_code,
            totals: MetaAdsMetricTotals::fromRow($row, $chosen, $present),
            localId: (int) $row->id,
        );
    }
}
