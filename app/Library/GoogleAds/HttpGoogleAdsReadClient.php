<?php

namespace App\Library\GoogleAds;

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
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;

/**
 * Google Ads Module V1 contract §2 — the real READ client. Every query comes
 * from GoogleAdsQueries; every request goes through GoogleAdsHttpTransport
 * (budget, headers, error normalisation, paging); every row is mapped by a
 * DTO factory that treats the JSON as untrusted (a row that does not parse is
 * skipped, never repaired).
 *
 * Read-only by construction: this class has no mutate method and no generic
 * request method.
 */
final class HttpGoogleAdsReadClient implements GoogleAdsReadClient
{
    public function __construct(private readonly GoogleAdsHttpTransport $transport)
    {
    }

    public function listAccessibleCustomers(string $accessToken): array
    {
        $payload = $this->transport->listAccessibleCustomers($accessToken);

        $ids = [];

        foreach (is_array($payload['resourceNames'] ?? null) ? $payload['resourceNames'] : [] as $resourceName) {
            $id = GoogleAdsCustomerId::fromResourceName($resourceName);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function customerDetails(GoogleAdsAccessContext $context): GoogleAdsCustomerDetails
    {
        $result = $this->transport->search($context, GoogleAdsQueries::customer());

        foreach ($result->rows as $row) {
            $details = GoogleAdsCustomerDetails::fromSearchRow($row);

            if ($details !== null && $details->customerId === $context->customerId) {
                return $details;
            }
        }

        throw GoogleAdsProviderException::unexpectedResponse();
    }

    public function managerClients(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        return $this->mapped($this->transport->search($context, GoogleAdsQueries::managerClients()), GoogleAdsManagedClient::fromSearchRow(...));
    }

    public function campaigns(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        return $this->mapped($this->transport->search($context, GoogleAdsQueries::campaigns()), GoogleAdsCampaignData::fromSearchRow(...));
    }

    public function adGroups(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        return $this->mapped($this->transport->search($context, GoogleAdsQueries::adGroups()), GoogleAdsAdGroupData::fromSearchRow(...));
    }

    public function keywords(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        $reports = [
            [$this->transport->search($context, GoogleAdsQueries::adGroupKeywords(false)), GoogleAdsKeywordData::fromAdGroupCriterionRow(...)],
            [$this->transport->search($context, GoogleAdsQueries::adGroupKeywords(true)), GoogleAdsKeywordData::fromAdGroupCriterionRow(...)],
            [$this->transport->search($context, GoogleAdsQueries::campaignNegativeKeywords()), GoogleAdsKeywordData::fromCampaignCriterionRow(...)],
        ];

        $rows = [];
        $truncated = false;
        $pages = 0;

        foreach ($reports as [$raw, $map]) {
            $mapped = $this->mapped($raw, $map);
            $rows = array_merge($rows, $mapped->rows);
            $truncated = $truncated || $mapped->truncated;
            $pages += $mapped->pagesFetched;
        }

        return new GoogleAdsReportResult($rows, $truncated, $pages);
    }

    public function dailyCampaignMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        return $this->mapped(
            $this->transport->search($context, GoogleAdsQueries::dailyCampaignMetrics($startDate, $endDate)),
            GoogleAdsDailyMetricData::fromCampaignRow(...),
        );
    }

    public function dailyKeywordMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        return $this->mapped(
            $this->transport->search($context, GoogleAdsQueries::dailyKeywordMetrics($startDate, $endDate)),
            GoogleAdsDailyMetricData::fromKeywordRow(...),
        );
    }

    public function searchTerms(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        return $this->mapped(
            $this->transport->search($context, GoogleAdsQueries::searchTerms($startDate, $endDate)),
            GoogleAdsSearchTermData::fromSearchRow(...),
        );
    }

    /**
     * @param  GoogleAdsReportResult<array<string, mixed>>  $raw
     * @param  callable(array<string, mixed>): ?object  $map
     */
    private function mapped(GoogleAdsReportResult $raw, callable $map): GoogleAdsReportResult
    {
        $rows = [];

        foreach ($raw->rows as $row) {
            $item = $map($row);

            if ($item !== null) {
                $rows[] = $item;
            }
        }

        return new GoogleAdsReportResult($rows, $raw->truncated, $raw->pagesFetched);
    }
}
