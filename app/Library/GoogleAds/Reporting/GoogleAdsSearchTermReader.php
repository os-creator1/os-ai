<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Models\GoogleAdsAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 contract §12 — the Search terms table, the waste
 * summary, and the ONE classification both the page and the recommendation
 * facts use.
 *
 * Aggregation: per-day search-term rows are summed over the period per
 * (term_hash, campaign, ad group). Classification is computed in SQL so it
 * can be filtered, sorted and paged without loading every term:
 *
 *   excluded         Google status EXCLUDED / ADDED_EXCLUDED on any day
 *   ignored          the owner's review_state = ignored (never potential waste)
 *   converting       conversions > 0
 *   potential_waste  conversions is PRESENT and = 0, and either
 *                    spend >= recommendations.waste_min_spend_micros, or
 *                    (waste_min_clicks > 0 and clicks >= waste_min_clicks)
 *   unreviewed       everything else — including terms with NULL conversions
 *                    (no conversion data is never called waste)
 */
final class GoogleAdsSearchTermReader
{
    public const SORTS = ['term', 'spend', 'clicks', 'impressions', 'conversions', 'cpl', 'conversion_rate'];

    private const NEGATIVE_LOOKUP_CHUNK = 500;

    public function __construct(
        private readonly GoogleAdsMetricQueries $metrics,
        private readonly GoogleAdsConfig $config,
    ) {
    }

    /**
     * @param  string|null  $class  a GoogleAdsSearchTermClass value; anything else = all
     * @return GoogleAdsPagedResult<GoogleAdsSearchTermRow>
     */
    public function page(
        GoogleAdsAccount $account,
        GoogleAdsPeriod $period,
        ?string $class = null,
        ?string $campaignUid = null,
        string $sort = 'spend',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 25,
    ): GoogleAdsPagedResult {
        [$page, $perPage, $offset] = GoogleAdsMetricQueries::window($page, $perPage);
        $query = $this->query($account, $period, $class, $campaignUid);
        $total = (clone $query)->count();

        $direction = GoogleAdsMetricQueries::direction($direction);
        $expression = match ($sort) {
            'term' => 't.term',
            default => $this->sortExpression($sort),
        };

        $rows = $query->orderByRaw("({$expression}) IS NULL ASC")
            ->orderByRaw("({$expression}) {$direction}")
            ->orderBy('t.term_hash')->orderBy('t.google_ads_ad_group_id')
            ->offset($offset)->limit($perPage)->get();

        return new GoogleAdsPagedResult($this->hydrate($account, $rows->all()), $total, $page, $perPage);
    }

    /** Classification of one search term in the period, or null when it has no rows. */
    public function classify(GoogleAdsAccount $account, GoogleAdsPeriod $period, string $term): ?GoogleAdsSearchTermClass
    {
        $row = $this->query($account, $period, null, null)
            ->where('t.term_hash', \App\Models\GoogleAdsSearchTerm::hashTerm($term))
            ->orderByDesc('t.spend_micros')
            ->first();

        return $row === null ? null : GoogleAdsSearchTermClass::from($row->term_class);
    }

    /** Potential-waste totals (actionable terms only) plus the top few terms. */
    public function wasteSummary(GoogleAdsAccount $account, GoogleAdsPeriod $period, ?string $campaignUid = null, int $top = 5): GoogleAdsWasteSummary
    {
        $hasRows = DB::table('google_ads_search_terms')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->whereBetween('metric_date', [$period->fromDate(), $period->toDate()])
            ->exists();

        if (! $hasRows) {
            return new GoogleAdsWasteSummary(false, null, null, null, null, null, []);
        }

        $rows = $this->hydrate(
            $account,
            $this->query($account, $period, GoogleAdsSearchTermClass::PotentialWaste->value, $campaignUid)
                ->orderByDesc('t.spend_micros')->orderBy('t.term_hash')->orderBy('t.google_ads_ad_group_id')
                ->get()->all(),
        );

        $actionable = array_values(array_filter($rows, static fn (GoogleAdsSearchTermRow $r): bool => ! $r->alreadyNegative));
        $covered = array_values(array_filter($rows, static fn (GoogleAdsSearchTermRow $r): bool => $r->alreadyNegative));

        return new GoogleAdsWasteSummary(
            hasData: true,
            termCount: count($actionable),
            spendMicros: (int) array_sum(array_map(static fn (GoogleAdsSearchTermRow $r): int => (int) $r->totals->spendMicros, $actionable)),
            clicks: (int) array_sum(array_map(static fn (GoogleAdsSearchTermRow $r): int => (int) $r->totals->clicks, $actionable)),
            alreadyExcludedCount: count($covered),
            alreadyExcludedSpendMicros: (int) array_sum(array_map(static fn (GoogleAdsSearchTermRow $r): int => (int) $r->totals->spendMicros, $covered)),
            topTerms: array_slice($actionable, 0, max(0, $top)),
        );
    }

    private function query(GoogleAdsAccount $account, GoogleAdsPeriod $period, ?string $class, ?string $campaignUid): Builder
    {
        $grouped = DB::table('google_ads_search_terms as s')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->whereBetween('metric_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('term_hash', 'google_ads_campaign_id', 'google_ads_ad_group_id')
            ->selectRaw(
                'term_hash, google_ads_campaign_id, google_ads_ad_group_id, MAX(search_term) AS term, '
                . 'SUM(cost_micros) AS spend_micros, SUM(clicks) AS clicks, SUM(impressions) AS impressions, '
                . 'SUM(conversions) AS conversions, SUM(conversions_value) AS conversions_value, '
                . 'COUNT(*) AS row_count, COUNT(DISTINCT metric_date) AS day_count, MAX(metric_date) AS last_date, '
                . "MAX(CASE WHEN targeting_status IN ('EXCLUDED','ADDED_EXCLUDED') THEN 1 ELSE 0 END) AS is_excluded, "
                . "MAX(CASE WHEN targeting_status IN ('ADDED','ADDED_EXCLUDED') THEN 1 ELSE 0 END) AS is_added, "
                . 'MAX(CASE WHEN targeting_status IS NOT NULL THEN 1 ELSE 0 END) AS has_status, '
                // "Ignored" is a decision about the term in its campaign / ad group, so ANY
                // ignored row of that term (any date) counts: days that arrive after the
                // owner ignored it are stored unreviewed and must not un-ignore it.
                . 'MAX(s.id) AS row_id, '
                . 'MAX(CASE WHEN EXISTS (SELECT 1 FROM google_ads_search_terms x WHERE x.business_id = s.business_id '
                . 'AND x.google_ads_account_id = s.google_ads_account_id AND x.term_hash = s.term_hash '
                . 'AND x.google_ads_campaign_id = s.google_ads_campaign_id AND x.google_ads_ad_group_id = s.google_ads_ad_group_id '
                . "AND x.review_state = 'ignored') THEN 1 ELSE 0 END) AS is_ignored, "
                . 'MAX(matched_keyword_text) AS matched_keyword_text, MAX(matched_keyword_match_type) AS matched_keyword_match_type'
            );

        [$classSql, $classBindings] = $this->classSql();

        $query = DB::query()->fromSub($grouped, 't')
            ->join('google_ads_campaigns as c', function ($join) {
                $join->on('c.id', '=', 't.google_ads_campaign_id');
            })
            ->join('google_ads_ad_groups as g', function ($join) {
                $join->on('g.id', '=', 't.google_ads_ad_group_id');
            })
            ->where('c.business_id', $account->business_id)
            ->where('c.google_ads_account_id', $account->id)
            ->where('g.business_id', $account->business_id)
            ->where('g.google_ads_account_id', $account->id)
            ->selectRaw("t.*, c.uid AS campaign_uid, c.name AS campaign_name, g.name AS ad_group_name, {$classSql} AS term_class", $classBindings);

        if ($campaignUid !== null) {
            $query->where('c.uid', $campaignUid);
        }

        $enum = $class === null ? null : GoogleAdsSearchTermClass::tryFrom($class);

        if ($enum !== null) {
            $query->whereRaw("({$classSql}) = ?", [...$classBindings, $enum->value]);
        }

        return $query;
    }

    /** @return array{0: string, 1: array<int, int>} */
    private function classSql(): array
    {
        $spend = $this->config->wasteMinSpendMicros();
        $clicks = $this->config->wasteMinClicks();

        $sql = "CASE WHEN t.is_excluded = 1 THEN 'excluded' "
            . "WHEN t.is_ignored = 1 THEN 'ignored' "
            . "WHEN t.conversions IS NOT NULL AND t.conversions > 0 THEN 'converting' "
            . "WHEN t.conversions IS NOT NULL AND t.conversions = 0 AND (t.spend_micros >= ? OR (? > 0 AND t.clicks >= ?)) THEN 'potential_waste' "
            . "ELSE 'unreviewed' END";

        return [$sql, [$spend, $clicks, $clicks]];
    }

    private function sortExpression(string $sort): string
    {
        return match ($sort) {
            'clicks' => 't.clicks',
            'impressions' => 't.impressions',
            'conversions' => 't.conversions',
            'cpl' => 'CASE WHEN t.conversions > 0 THEN t.spend_micros / t.conversions END',
            'conversion_rate' => 'CASE WHEN t.clicks > 0 AND t.conversions IS NOT NULL THEN t.conversions / t.clicks END',
            default => 't.spend_micros',
        };
    }

    /**
     * @param  array<int, object>  $raw
     * @return array<int, GoogleAdsSearchTermRow>
     */
    private function hydrate(GoogleAdsAccount $account, array $raw): array
    {
        $negatives = $this->negativeIndex($account, $raw);
        $rows = [];

        foreach ($raw as $row) {
            $key = mb_strtolower(trim((string) $row->term));
            $covered = false;

            foreach ($negatives[$key] ?? [] as [$campaignId, $adGroupId]) {
                if ($adGroupId === null ? $campaignId === (int) $row->google_ads_campaign_id : $adGroupId === (int) $row->google_ads_ad_group_id) {
                    $covered = true;

                    break;
                }
            }

            $rows[] = new GoogleAdsSearchTermRow(
                term: (string) $row->term,
                termHash: (string) $row->term_hash,
                campaignUid: (string) $row->campaign_uid,
                campaignName: (string) $row->campaign_name,
                adGroupName: (string) $row->ad_group_name,
                matchedKeywordText: $row->matched_keyword_text,
                matchedKeywordMatchType: GoogleAdsMatchType::tryFrom((string) $row->matched_keyword_match_type),
                targetingStatus: $this->targetingStatus($row),
                reviewState: (int) $row->is_ignored === 1 ? GoogleAdsSearchTermReviewState::Ignored : GoogleAdsSearchTermReviewState::Unreviewed,
                classification: GoogleAdsSearchTermClass::from((string) $row->term_class),
                alreadyNegative: $covered,
                totals: GoogleAdsMetricTotals::fromRow($row),
                internal: ['campaign_id' => (int) $row->google_ads_campaign_id, 'ad_group_id' => (int) $row->google_ads_ad_group_id, 'search_term_id' => (int) $row->row_id],
            );
        }

        return $rows;
    }

    private function targetingStatus(object $row): ?GoogleAdsSearchTermStatus
    {
        $excluded = (int) $row->is_excluded === 1;
        $added = (int) $row->is_added === 1;

        return match (true) {
            $excluded && $added => GoogleAdsSearchTermStatus::AddedExcluded,
            $excluded => GoogleAdsSearchTermStatus::Excluded,
            $added => GoogleAdsSearchTermStatus::Added,
            (int) $row->has_status === 1 => GoogleAdsSearchTermStatus::None,
            default => null,
        };
    }

    /**
     * lower(term) => list of [campaignId, adGroupId|null] for enabled
     * synced negatives with exactly that text (any match type, case-insensitive).
     *
     * @param  array<int, object>  $raw
     * @return array<string, array<int, array{0: int, 1: ?int}>>
     */
    private function negativeIndex(GoogleAdsAccount $account, array $raw): array
    {
        $terms = array_values(array_unique(array_map(static fn (object $r): string => mb_strtolower(trim((string) $r->term)), $raw)));
        $index = [];

        foreach (array_chunk($terms, self::NEGATIVE_LOOKUP_CHUNK) as $chunk) {
            $negatives = DB::table('google_ads_keywords')
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id)
                ->where('is_negative', true)
                ->where('status', 'ENABLED')
                ->whereRaw('LOWER(text) IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)
                ->get(['text', 'google_ads_campaign_id', 'google_ads_ad_group_id']);

            foreach ($negatives as $negative) {
                $index[mb_strtolower(trim((string) $negative->text))][] = [
                    (int) $negative->google_ads_campaign_id,
                    $negative->google_ads_ad_group_id === null ? null : (int) $negative->google_ads_ad_group_id,
                ];
            }
        }

        return $index;
    }
}
