<?php

namespace App\Library\MetaAds\Contracts;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\DTO\MetaAds\MetaAdsAdData;
use App\DTO\MetaAds\MetaAdsAdSetData;
use App\DTO\MetaAds\MetaAdsCampaignData;
use App\DTO\MetaAds\MetaFrequencyRow;
use App\DTO\MetaAds\MetaInsightRow;
use App\DTO\MetaAds\MetaReportPage;
use App\Exceptions\MetaAds\MetaProviderException;

/**
 * Meta Ads Module V1 contract §2/§6 — the READ half of the provider seam.
 *
 * READ-ONLY BY CONSTRUCTION: every method issues GETs only. There is no
 * generic request()/call() method, so product code cannot reach an arbitrary
 * endpoint. Each list method returns ONE page and the `after` cursor of the
 * next; the caller (the sync job) owns the page / row caps and passes the
 * cursor back. One method call = one outbound request = one reserve() of the
 * MetaAdsCallCounter (listAdAccounts pages internally within the configured
 * caps and reserves once per page).
 *
 * `$adAccountId` is digits only (no `act_`); `$level` is `campaign|ad_set|ad`;
 * dates are `Y-m-d` in the ACCOUNT time zone, inclusive.
 */
interface MetaReadClient
{
    /**
     * `GET /me/adaccounts` — every account the token's user can reach, within
     * the configured page / row caps.
     *
     * @return array<int, MetaAdsAccountCandidate>
     *
     * @throws MetaProviderException
     */
    public function listAdAccounts(string $accessToken): array;

    /**
     * `GET /act_{id}`.
     *
     * @throws MetaProviderException
     */
    public function accountDetails(string $accessToken, string $adAccountId): MetaAdsAccountCandidate;

    /**
     * @return MetaReportPage<MetaAdsCampaignData>
     *
     * @throws MetaProviderException
     */
    public function campaigns(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage;

    /**
     * @return MetaReportPage<MetaAdsAdSetData>
     *
     * @throws MetaProviderException
     */
    public function adSets(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage;

    /**
     * @return MetaReportPage<MetaAdsAdData>
     *
     * @throws MetaProviderException
     */
    public function ads(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage;

    /**
     * Daily insights (`time_increment=1`) at one level.
     *
     * @param  'campaign'|'ad_set'|'ad'  $level
     * @return MetaReportPage<MetaInsightRow>
     *
     * @throws MetaProviderException
     */
    public function insights(string $accessToken, string $adAccountId, string $level, string $since, string $until, ?string $cursor = null): MetaReportPage;

    /**
     * Ad-set reach + frequency over [$since, $until] from a non-daily call.
     *
     * @return MetaReportPage<MetaFrequencyRow>
     *
     * @throws MetaProviderException
     */
    public function frequency7d(string $accessToken, string $adAccountId, string $since, string $until, ?string $cursor = null): MetaReportPage;
}
