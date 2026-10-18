<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Models\GoogleAdsAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 contract §4 — keyword performance and the separate
 * negative-keyword list.
 *
 * Keyword metrics come from KEYWORD-level daily rows joined on
 * entity_key = external_criterion_id (unique per account). Negatives have no
 * metrics and are listed apart so they never appear in the performance
 * table. Everything is scoped by Business + account.
 */
final class GoogleAdsKeywordReader
{
    public const SORTS = ['keyword', 'campaign', 'status', 'quality_score', ...GoogleAdsMetricQueries::METRIC_SORTS];

    public function __construct(private readonly GoogleAdsMetricQueries $metrics)
    {
    }

    /**
     * @param  string|null  $campaignUid  restrict to one campaign (uid)
     * @param  string|null  $status  ENABLED|PAUSED|REMOVED; anything else = all
     * @return GoogleAdsPagedResult<GoogleAdsKeywordRow>
     */
    public function page(
        GoogleAdsAccount $account,
        GoogleAdsPeriod $period,
        ?string $campaignUid = null,
        ?string $status = null,
        string $sort = 'spend',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 25,
    ): GoogleAdsPagedResult {
        [$page, $perPage, $offset] = GoogleAdsMetricQueries::window($page, $perPage);

        $query = $this->base($account, $campaignUid, $status)
            ->leftJoinSub(
                $this->metrics->perEntity($account, GoogleAdsMetricLevel::Keyword, $period->fromDate(), $period->toDate()),
                'm',
                'm.entity_key',
                '=',
                'k.external_criterion_id',
            )
            ->where('k.is_negative', false)
            ->selectRaw($this->columns() . ', m.spend_micros, m.clicks, m.impressions, m.conversions, m.conversions_value, m.row_count, m.day_count, m.last_date');

        $total = (clone $query)->count();

        $direction = GoogleAdsMetricQueries::direction($direction);
        $expression = match ($sort) {
            'keyword' => 'k.text',
            'campaign' => 'c.name',
            'status' => 'k.status',
            'quality_score' => 'k.quality_score',
            default => GoogleAdsMetricQueries::metricSortExpression($sort) ?? 'm.spend_micros',
        };

        $rows = $query->orderByRaw("({$expression}) IS NULL ASC")
            ->orderByRaw("({$expression}) {$direction}")
            ->orderBy('k.id')
            ->offset($offset)->limit($perPage)->get();

        return new GoogleAdsPagedResult($rows->map(fn (object $row) => $this->hydrate($row))->all(), $total, $page, $perPage);
    }

    /**
     * Negative keywords (campaign- and ad-group-level), listed separately.
     *
     * @return array<int, GoogleAdsKeywordRow>
     */
    public function negatives(GoogleAdsAccount $account, ?string $campaignUid = null, int $limit = 500): array
    {
        return $this->base($account, $campaignUid, null)
            ->where('k.is_negative', true)
            ->selectRaw($this->columns())
            ->orderBy('c.name')->orderBy('k.text')->orderBy('k.id')
            ->limit(max(1, min(2000, $limit)))
            ->get()
            ->map(fn (object $row) => $this->hydrate($row))
            ->all();
    }

    private function base(GoogleAdsAccount $account, ?string $campaignUid, ?string $status): Builder
    {
        $query = DB::table('google_ads_keywords as k')
            ->join('google_ads_campaigns as c', function ($join) {
                $join->on('c.id', '=', 'k.google_ads_campaign_id')
                    ->on('c.google_ads_account_id', '=', 'k.google_ads_account_id');
            })
            ->leftJoin('google_ads_ad_groups as g', 'g.id', '=', 'k.google_ads_ad_group_id')
            ->where('k.business_id', $account->business_id)
            ->where('k.google_ads_account_id', $account->id)
            ->where('c.business_id', $account->business_id);

        if ($campaignUid !== null) {
            $query->where('c.uid', $campaignUid);
        }

        $enum = $status === null ? null : GoogleAdsEntityStatus::tryFrom(strtoupper(trim($status)));

        if ($enum !== null) {
            $query->where('k.status', $enum->value);
        }

        return $query;
    }

    private function columns(): string
    {
        return 'k.id, k.uid, k.text, k.match_type, k.status, k.level, k.is_negative, k.quality_score, k.external_criterion_id, '
            . 'c.uid AS campaign_uid, c.name AS campaign_name, g.name AS ad_group_name';
    }

    private function hydrate(object $row): GoogleAdsKeywordRow
    {
        return new GoogleAdsKeywordRow(
            uid: (string) $row->uid,
            text: (string) $row->text,
            matchType: GoogleAdsMatchType::tryFrom((string) $row->match_type),
            status: GoogleAdsEntityStatus::fromProvider($row->status),
            level: GoogleAdsKeywordLevel::from((string) $row->level),
            isNegative: (bool) $row->is_negative,
            campaignUid: (string) $row->campaign_uid,
            campaignName: (string) $row->campaign_name,
            adGroupName: $row->ad_group_name,
            qualityScore: $row->quality_score === null ? null : (int) $row->quality_score,
            totals: property_exists($row, 'row_count') ? GoogleAdsMetricTotals::fromRow($row) : GoogleAdsMetricTotals::empty(),
            internal: ['external_criterion_id' => (string) $row->external_criterion_id],
        );
    }
}
