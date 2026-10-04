<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPagedResult;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsConfig;
use App\Models\MetaAdsAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §5 — the Ads table, from cached rows only,
 * with a read-only creative summary. Same shape and guarantees as
 * MetaAdsAdSetReader (uid addressing inside the Business's account, ONE
 * aggregate sub-select, null metrics for an ad without rows, 3 queries per
 * page).
 *
 * The creative thumbnail is shown ONLY when its URL passes
 * MetaAdsConfig::allowedThumbnailUrl (https + allow-listed host); otherwise
 * `thumbnail_url` is null. Title/body are customer data: views must escape.
 */
final class MetaAdsAdReader
{
    use MetaAdsEntityReaderSupport;

    public const SORTS = ['name', 'status', 'campaign', 'ad_set', ...MetaAdsMetricQueries::METRIC_SORTS];

    public function __construct(
        private readonly MetaAdsMetricQueries $metrics,
        private readonly MetaAdsConfig $config,
    ) {
    }

    /**
     * @return GoogleAdsPagedResult<MetaAdsAdRow>
     */
    public function page(
        MetaAdsAccount $account,
        GoogleAdsPeriod $period,
        ?string $status = null,
        ?string $campaignUid = null,
        ?string $adSetUid = null,
        string $sort = 'spend',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 25,
    ): GoogleAdsPagedResult {
        [$page, $perPage, $offset] = MetaAdsMetricQueries::window($page, $perPage);
        $base = $this->base($account, $status, $campaignUid, $adSetUid);
        $total = (clone $base)->count();

        $chosen = $this->select($base, $account, $period);
        $this->applySort($base, $sort, $direction, $chosen, [
            'name' => 'a.name',
            'status' => 'a.status',
            'campaign' => 'c.name',
            'ad_set' => 's.name',
        ], 'a.id');

        $rows = $base->offset($offset)->limit($perPage)->get();
        $present = $chosen && $rows->isNotEmpty() && $this->resultPresent($account, $period, MetaAdsLevel::Ad);

        return new GoogleAdsPagedResult(
            $rows->map(fn (object $row) => $this->hydrate($row, $account, $chosen, $present))->all(),
            $total,
            $page,
            $perPage,
        );
    }

    /** @return array<int, MetaAdsAdRow> */
    public function list(MetaAdsAccount $account, GoogleAdsPeriod $period, ?string $status = null, ?string $campaignUid = null, ?string $adSetUid = null, int $limit = 200): array
    {
        return $this->page($account, $period, $status, $campaignUid, $adSetUid, 'spend', 'desc', 1, $limit)->items;
    }

    public function find(MetaAdsAccount $account, string $adUid, GoogleAdsPeriod $period): ?MetaAdsAdRow
    {
        $query = $this->base($account, null, null, null)->where('a.uid', $adUid);
        $chosen = $this->select($query, $account, $period);
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $account, $chosen, $chosen && $this->resultPresent($account, $period, MetaAdsLevel::Ad));
    }

    private function base(MetaAdsAccount $account, ?string $status, ?string $campaignUid, ?string $adSetUid): Builder
    {
        $query = DB::table('meta_ads_ads as a')
            ->join('meta_ads_campaigns as c', function ($join) {
                $join->on('c.id', '=', 'a.meta_ads_campaign_id')
                    ->on('c.meta_ads_account_id', '=', 'a.meta_ads_account_id');
            })
            ->join('meta_ads_ad_sets as s', function ($join) {
                $join->on('s.id', '=', 'a.meta_ads_ad_set_id')
                    ->on('s.meta_ads_account_id', '=', 'a.meta_ads_account_id');
            })
            ->where('a.business_id', $account->business_id)
            ->where('a.meta_ads_account_id', $account->id);

        $enum = $this->statusValue($status);

        if ($enum !== null) {
            $query->where('a.status', $enum);
        }

        if ($campaignUid !== null) {
            $query->where('c.uid', $campaignUid);
        }

        if ($adSetUid !== null) {
            $query->where('s.uid', $adSetUid);
        }

        return $query;
    }

    private function select(Builder $query, MetaAdsAccount $account, GoogleAdsPeriod $period): bool
    {
        $query->selectRaw('a.id, a.uid, a.name, a.status, a.effective_status, a.creative_title, a.creative_body, '
            . 'a.creative_thumbnail_url, a.creative_object_type, c.uid AS campaign_uid, c.name AS campaign_name, '
            . 's.uid AS ad_set_uid, s.name AS ad_set_name');

        return $this->metrics->joinEntityMetrics($query, $account, MetaAdsLevel::Ad, $period->fromDate(), $period->toDate(), 'a.id');
    }

    private function hydrate(object $row, MetaAdsAccount $account, bool $chosen, bool $present): MetaAdsAdRow
    {
        return new MetaAdsAdRow(
            uid: (string) $row->uid,
            name: (string) $row->name,
            status: (string) $row->status,
            effectiveStatus: $row->effective_status === null ? null : (string) $row->effective_status,
            campaignUid: (string) $row->campaign_uid,
            campaignName: (string) $row->campaign_name,
            adSetUid: (string) $row->ad_set_uid,
            adSetName: (string) $row->ad_set_name,
            creative: [
                'title' => $row->creative_title === null ? null : (string) $row->creative_title,
                'body' => $row->creative_body === null ? null : (string) $row->creative_body,
                'thumbnail_url' => $this->config->allowedThumbnailUrl($row->creative_thumbnail_url === null ? null : (string) $row->creative_thumbnail_url),
                'object_type' => $row->creative_object_type === null ? null : (string) $row->creative_object_type,
            ],
            currencyCode: (string) $account->currency_code,
            totals: MetaAdsMetricTotals::fromRow($row, $chosen, $present),
            localId: (int) $row->id,
        );
    }
}
