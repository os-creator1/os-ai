<?php

namespace App\Library\GoogleAds\Contracts;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsAdGroupData;
use App\DTO\GoogleAds\GoogleAdsCampaignData;
use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsDailyMetricData;
use App\DTO\GoogleAds\GoogleAdsKeywordData;
use App\DTO\GoogleAds\GoogleAdsManagedClient;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\DTO\GoogleAds\GoogleAdsSearchTermData;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;

/**
 * Google Ads Module V1 contract §2 — the READ half of the provider seam.
 *
 * READ-ONLY BY CONSTRUCTION: every method issues a GET (customer listing) or
 * a `googleAds:search` POST (a query, not a write). There is no generic
 * request()/call()/send() method, so product code cannot reach an arbitrary
 * endpoint through this seam.
 *
 * Every method takes a GoogleAdsAccessContext carrying a per-unit-of-work
 * access token (never persisted) and the operating / login customer ids.
 * Reports return a bounded GoogleAdsReportResult whose `truncated` flag is
 * true when a page or row cap was hit. Date arguments are `Y-m-d` in the
 * ACCOUNT time zone (the caller computes the window), inclusive.
 *
 * Every provider failure is a GoogleAdsProviderException.
 */
interface GoogleAdsReadClient
{
    /**
     * Customer ids (10 digits) directly accessible to the authorised user.
     *
     * @return array<int, string>
     *
     * @throws GoogleAdsProviderException
     */
    public function listAccessibleCustomers(string $accessToken): array;

    /**
     * The `customer` resource of $context->customerId.
     *
     * @throws GoogleAdsProviderException
     */
    public function customerDetails(GoogleAdsAccessContext $context): GoogleAdsCustomerDetails;

    /**
     * `customer_client` rows under the manager $context->customerId (callers
     * pass the manager as both customerId and loginCustomerId).
     *
     * @return GoogleAdsReportResult<GoogleAdsManagedClient>
     *
     * @throws GoogleAdsProviderException
     */
    public function managerClients(GoogleAdsAccessContext $context): GoogleAdsReportResult;

    /**
     * @return GoogleAdsReportResult<GoogleAdsCampaignData>
     *
     * @throws GoogleAdsProviderException
     */
    public function campaigns(GoogleAdsAccessContext $context): GoogleAdsReportResult;

    /**
     * @return GoogleAdsReportResult<GoogleAdsAdGroupData>
     *
     * @throws GoogleAdsProviderException
     */
    public function adGroups(GoogleAdsAccessContext $context): GoogleAdsReportResult;

    /**
     * Positive keywords, ad-group negatives and campaign negatives, merged.
     * `truncated` is true if ANY of the three reports was truncated.
     *
     * @return GoogleAdsReportResult<GoogleAdsKeywordData>
     *
     * @throws GoogleAdsProviderException
     */
    public function keywords(GoogleAdsAccessContext $context): GoogleAdsReportResult;

    /**
     * @return GoogleAdsReportResult<GoogleAdsDailyMetricData>
     *
     * @throws GoogleAdsProviderException
     */
    public function dailyCampaignMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult;

    /**
     * Keyword-level daily metrics (from `keyword_view`).
     *
     * @return GoogleAdsReportResult<GoogleAdsDailyMetricData>
     *
     * @throws GoogleAdsProviderException
     */
    public function dailyKeywordMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult;

    /**
     * @return GoogleAdsReportResult<GoogleAdsSearchTermData>
     *
     * @throws GoogleAdsProviderException
     */
    public function searchTerms(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult;
}
