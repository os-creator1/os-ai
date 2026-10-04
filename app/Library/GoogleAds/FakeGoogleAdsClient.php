<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsAdGroupData;
use App\DTO\GoogleAds\GoogleAdsCampaignData;
use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsDailyMetricData;
use App\DTO\GoogleAds\GoogleAdsKeywordData;
use App\DTO\GoogleAds\GoogleAdsManagedClient;
use App\DTO\GoogleAds\GoogleAdsMutationResult;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\DTO\GoogleAds\GoogleAdsSearchTermData;
use App\DTO\GoogleBusinessProfile\GoogleAccessGrant;
use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;

/**
 * Google Ads Module V1 contract D1 — the in-memory provider used by every
 * automated test and by browser acceptance (config `google_ads.driver` =
 * `fake`, which GoogleAdsConfig REFUSES in production). It implements all
 * three provider interfaces, so one instance is the whole fake Google.
 *
 * It is SCRIPTABLE rather than canned:
 *   - usePhotoBoothFixture() loads the deterministic PhotoBoothFixture; any
 *     of customers / manager clients / per-customer data can be set directly.
 *   - failNext($method, $exception, $times, $applyBeforeFailing) injects a
 *     provider failure into a named method (429, invalid_grant, timeout, an
 *     AMBIGUOUS mutate timeout …). With $applyBeforeFailing, a mutation takes
 *     effect on the fake provider and THEN fails, which is exactly the
 *     "timed out but actually applied" case reconciliation must survive.
 *   - paginate() / limitRows() simulate multi-page reports and the row cap
 *     (`truncated` = true), honouring sync.max_pages_per_report /
 *     max_rows_per_report.
 *   - every call is recorded in $calls (method, customer id, login customer
 *     id, safe args; NEVER a token) for assertions.
 *   - when a GoogleAdsCallBudget is supplied, every call reserves exactly one
 *     request per page, like the real client, so budget behaviour is testable.
 *
 * It enforces the one provider rule callers most often get wrong: a customer
 * that is reachable only through a manager requires that manager as
 * login-customer-id, otherwise the call fails with `access_denied`
 * (USER_PERMISSION_DENIED).
 */
final class FakeGoogleAdsClient implements GoogleAdsAuthClient, GoogleAdsMutationClient, GoogleAdsReadClient
{
    /**
     * @var array<int, array{method: string, customer_id: ?string, login_customer_id: ?string, args: array<string, mixed>}>
     */
    public array $calls = [];

    /** @var array<int, string> */
    private array $accessible = [];

    /** @var array<string, GoogleAdsCustomerDetails> */
    private array $customers = [];

    /** @var array<string, array<int, GoogleAdsManagedClient>> */
    private array $managerClientRows = [];

    /**
     * @var array<string, array{campaigns: array<int, GoogleAdsCampaignData>, adGroups: array<int, GoogleAdsAdGroupData>, keywords: array<int, GoogleAdsKeywordData>, dailyCampaign: array<int, GoogleAdsDailyMetricData>, dailyKeyword: array<int, GoogleAdsDailyMetricData>, searchTerms: array<int, GoogleAdsSearchTermData>}>
     */
    private array $datasets = [];

    /** @var array<string, array<int, array{0: GoogleAdsProviderException, 1: bool}>> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $rowCaps = [];

    private int $rowsPerPage = 10000;

    private ?string $refreshToken = 'fake-refresh-token';

    private string $grantedScopes;

    private int $tokenSequence = 0;

    private int $criterionSequence = 9_000_000_000;

    public function __construct(
        private readonly ?GoogleAdsCallBudget $budget = null,
        private readonly ?GoogleAdsConfig $config = null,
    ) {
        $this->grantedScopes = GoogleConnectionProduct::GoogleAds->scope();
    }

    // ------------------------------------------------------------------
    // Scripting
    // ------------------------------------------------------------------

    public function usePhotoBoothFixture(?PhotoBoothFixture $fixture = null): self
    {
        $fixture ??= new PhotoBoothFixture();

        $this->accessible = $fixture->accessibleCustomerIds();
        $this->customers = $fixture->customers();
        $this->managerClientRows = [PhotoBoothFixture::MANAGER_ID => $fixture->managerClients()];
        $this->datasets[PhotoBoothFixture::CUSTOMER_ID] = [
            'campaigns' => $fixture->campaigns(),
            'adGroups' => $fixture->adGroups(),
            'keywords' => $fixture->keywords(),
            'dailyCampaign' => $fixture->dailyCampaignMetrics(),
            'dailyKeyword' => $fixture->dailyKeywordMetrics(),
            'searchTerms' => $fixture->searchTerms(),
        ];

        return $this;
    }

    /** @param  array<int, string>  $customerIds */
    public function withAccessibleCustomers(array $customerIds): self
    {
        $this->accessible = array_values($customerIds);

        return $this;
    }

    public function withCustomer(GoogleAdsCustomerDetails $details): self
    {
        $this->customers[$details->customerId] = $details;

        return $this;
    }

    /** @param  array<int, GoogleAdsManagedClient>  $clients */
    public function withManagerClients(string $managerId, array $clients): self
    {
        $this->managerClientRows[$managerId] = $clients;

        return $this;
    }

    /**
     * @param  array{campaigns?: array<int, GoogleAdsCampaignData>, adGroups?: array<int, GoogleAdsAdGroupData>, keywords?: array<int, GoogleAdsKeywordData>, dailyCampaign?: array<int, GoogleAdsDailyMetricData>, dailyKeyword?: array<int, GoogleAdsDailyMetricData>, searchTerms?: array<int, GoogleAdsSearchTermData>}  $data
     */
    public function withDataset(string $customerId, array $data): self
    {
        $this->datasets[$customerId] = array_merge([
            'campaigns' => [], 'adGroups' => [], 'keywords' => [],
            'dailyCampaign' => [], 'dailyKeyword' => [], 'searchTerms' => [],
        ], $data);

        return $this;
    }

    /**
     * The next $times calls to $method (an interface method name) throw
     * $exception. For a mutation, $applyBeforeFailing makes the change take
     * effect on the fake provider before the throw.
     */
    public function failNext(string $method, GoogleAdsProviderException $exception, int $times = 1, bool $applyBeforeFailing = false): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$method][] = [$exception, $applyBeforeFailing];
        }

        return $this;
    }

    /** Reports from $method return at most $maxRows rows with `truncated` = true. */
    public function limitRows(string $method, int $maxRows): self
    {
        $this->rowCaps[$method] = max(0, $maxRows);

        return $this;
    }

    /** Simulates Google paging: each page beyond the first reserves one more call. */
    public function paginate(int $rowsPerPage): self
    {
        $this->rowsPerPage = max(1, $rowsPerPage);

        return $this;
    }

    /** The next authorization-code exchange returns no refresh token. */
    public function grantNoRefreshToken(): self
    {
        $this->refreshToken = null;

        return $this;
    }

    public function grantScopes(string $scopes): self
    {
        $this->grantedScopes = $scopes;

        return $this;
    }

    /** @return array<int, array{method: string, customer_id: ?string, login_customer_id: ?string, args: array<string, mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    public function callCount(?string $method = null): int
    {
        return $method === null ? count($this->calls) : count($this->callsTo($method));
    }

    public function campaignStatus(string $customerId, string $campaignId): ?GoogleAdsEntityStatus
    {
        foreach ($this->datasets[$customerId]['campaigns'] ?? [] as $campaign) {
            if ($campaign->externalCampaignId === $campaignId) {
                return $campaign->status;
            }
        }

        return null;
    }

    /** @return array<int, GoogleAdsKeywordData> */
    public function keywordsOf(string $customerId): array
    {
        return $this->datasets[$customerId]['keywords'] ?? [];
    }

    // ------------------------------------------------------------------
    // GoogleAdsAuthClient
    // ------------------------------------------------------------------

    public function authorizationUrl(string $signedState, bool $forceConsent): string
    {
        $this->record('authorizationUrl', null, ['force_consent' => $forceConsent]);

        $query = [
            'response_type' => 'code',
            'scope' => GoogleConnectionProduct::GoogleAds->scope(),
            'access_type' => 'offline',
            'include_granted_scopes' => 'false',
            'state' => $signedState,
        ];

        if ($forceConsent) {
            $query['prompt'] = 'consent';
        }

        return 'https://accounts.google.test/o/oauth2/v2/auth?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant
    {
        $this->enter('exchangeAuthorizationCode', null);

        return new GoogleTokenGrant($this->refreshToken, 'fake-access-token-' . ++$this->tokenSequence, 3600, $this->grantedScopes);
    }

    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant
    {
        $this->enter('exchangeRefreshToken', null);

        return new GoogleAccessGrant('fake-access-token-' . ++$this->tokenSequence, 3600);
    }

    // ------------------------------------------------------------------
    // GoogleAdsReadClient
    // ------------------------------------------------------------------

    public function listAccessibleCustomers(string $accessToken): array
    {
        $this->enter('listAccessibleCustomers', null);

        return $this->accessible;
    }

    public function customerDetails(GoogleAdsAccessContext $context): GoogleAdsCustomerDetails
    {
        $this->enter('customerDetails', $context);
        $this->authorize($context);

        if (isset($this->customers[$context->customerId])) {
            return $this->customers[$context->customerId];
        }

        foreach ($this->managerClientRows as $clients) {
            foreach ($clients as $client) {
                if ($client->customerId === $context->customerId) {
                    return new GoogleAdsCustomerDetails($client->customerId, $client->name, $client->currencyCode, $client->timeZone, $client->isManager, $client->isTest, $client->status);
                }
            }
        }

        throw GoogleAdsProviderException::notFound();
    }

    public function managerClients(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        $this->enter('managerClients', $context);
        $this->authorize($context);

        if (! isset($this->managerClientRows[$context->customerId])) {
            // Not a manager: Google rejects customer_client on a non-manager.
            throw GoogleAdsProviderException::accessDenied();
        }

        return $this->report('managerClients', $this->managerClientRows[$context->customerId]);
    }

    public function campaigns(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        return $this->dataReport('campaigns', 'campaigns', $context);
    }

    public function adGroups(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        return $this->dataReport('adGroups', 'adGroups', $context);
    }

    public function keywords(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        return $this->dataReport('keywords', 'keywords', $context);
    }

    public function dailyCampaignMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        return $this->dataReport('dailyCampaignMetrics', 'dailyCampaign', $context, $startDate, $endDate);
    }

    public function dailyKeywordMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        return $this->dataReport('dailyKeywordMetrics', 'dailyKeyword', $context, $startDate, $endDate);
    }

    public function searchTerms(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        return $this->dataReport('searchTerms', 'searchTerms', $context, $startDate, $endDate);
    }

    // ------------------------------------------------------------------
    // GoogleAdsMutationClient
    // ------------------------------------------------------------------

    public function setCampaignStatus(GoogleAdsAccessContext $context, string $campaignId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
    {
        $failure = $this->enter('setCampaignStatus', $context, ['campaign_id' => $campaignId, 'status' => $status->value], mutation: true);
        $id = GoogleAdsJson::id($campaignId) ?? throw GoogleAdsProviderException::validation();

        if (! $status->isWritable()) {
            throw GoogleAdsProviderException::validation();
        }

        $this->authorize($context);

        $index = $this->findCampaign($context->customerId, $id);

        if ($index === null) {
            throw GoogleAdsProviderException::notFound();
        }

        $this->settle($failure, function () use ($context, $index, $status): void {
            $c = $this->datasets[$context->customerId]['campaigns'][$index];
            $this->datasets[$context->customerId]['campaigns'][$index] = new GoogleAdsCampaignData(
                $c->externalCampaignId, $c->name, $status, $c->channelType, $c->biddingStrategyType,
                $c->budgetExternalId, $c->budgetAmountMicros, $c->budgetShared,
            );
        });

        return new GoogleAdsMutationResult('customers/' . $context->customerId . '/campaigns/' . $id);
    }

    public function setKeywordStatus(GoogleAdsAccessContext $context, string $adGroupId, string $criterionId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
    {
        $failure = $this->enter('setKeywordStatus', $context, ['ad_group_id' => $adGroupId, 'criterion_id' => $criterionId, 'status' => $status->value], mutation: true);
        $adGroup = GoogleAdsJson::id($adGroupId) ?? throw GoogleAdsProviderException::validation();
        $criterion = GoogleAdsJson::id($criterionId) ?? throw GoogleAdsProviderException::validation();

        if (! $status->isWritable()) {
            throw GoogleAdsProviderException::validation();
        }

        $this->authorize($context);

        $tail = $adGroup . '~' . $criterion;
        $index = null;

        foreach ($this->datasets[$context->customerId]['keywords'] ?? [] as $i => $keyword) {
            if ($keyword->level === GoogleAdsKeywordLevel::AdGroup && $keyword->externalCriterionId === $tail) {
                $index = $i;
            }
        }

        if ($index === null) {
            throw GoogleAdsProviderException::notFound();
        }

        $this->settle($failure, function () use ($context, $index, $status): void {
            $k = $this->datasets[$context->customerId]['keywords'][$index];
            $this->datasets[$context->customerId]['keywords'][$index] = new GoogleAdsKeywordData(
                $k->externalCriterionId, $k->level, $k->externalCampaignId, $k->externalAdGroupId, $k->text,
                $k->matchType, $status, $k->isNegative, $k->qualityScore,
            );
        });

        return new GoogleAdsMutationResult('customers/' . $context->customerId . '/adGroupCriteria/' . $tail);
    }

    public function addNegativeKeyword(GoogleAdsAccessContext $context, GoogleAdsKeywordLevel $scope, string $parentId, string $text, GoogleAdsMatchType $matchType): GoogleAdsMutationResult
    {
        $failure = $this->enter('addNegativeKeyword', $context, ['scope' => $scope->value, 'parent_id' => $parentId, 'text' => $text, 'match_type' => $matchType->value], mutation: true);
        $parent = GoogleAdsJson::id($parentId) ?? throw GoogleAdsProviderException::validation();
        $keywordText = GoogleAdsKeywordText::normalize($text) ?? throw GoogleAdsProviderException::validation();

        $this->authorize($context);

        $campaignId = null;

        if ($scope === GoogleAdsKeywordLevel::Campaign) {
            $campaignId = $this->findCampaign($context->customerId, $parent) === null ? null : $parent;
        } else {
            foreach ($this->datasets[$context->customerId]['adGroups'] ?? [] as $adGroup) {
                if ($adGroup->externalAdGroupId === $parent) {
                    $campaignId = $adGroup->externalCampaignId;
                }
            }
        }

        if ($campaignId === null) {
            throw GoogleAdsProviderException::notFound();
        }

        foreach ($this->datasets[$context->customerId]['keywords'] ?? [] as $existing) {
            if ($existing->isNegative && $existing->level === $scope && $existing->matchType === $matchType
                && mb_strtolower($existing->text) === mb_strtolower($keywordText)
                && ($scope === GoogleAdsKeywordLevel::Campaign ? $existing->externalCampaignId : $existing->externalAdGroupId) === $parent) {
                // Google rejects a duplicate criterion.
                throw GoogleAdsProviderException::validation();
            }
        }

        $tail = $parent . '~' . ++$this->criterionSequence;

        $this->settle($failure, function () use ($context, $scope, $parent, $campaignId, $keywordText, $matchType, $tail): void {
            $this->datasets[$context->customerId]['keywords'][] = new GoogleAdsKeywordData(
                $tail, $scope, $campaignId, $scope === GoogleAdsKeywordLevel::AdGroup ? $parent : null,
                $keywordText, $matchType, GoogleAdsEntityStatus::Enabled, true, null,
            );
        });

        return new GoogleAdsMutationResult('customers/' . $context->customerId
            . ($scope === GoogleAdsKeywordLevel::Campaign ? '/campaignCriteria/' : '/adGroupCriteria/') . $tail);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Records the call, reserves one request against the call budget, and
     * takes any scripted failure. A non-mutation failure throws here; a
     * mutation failure is returned so settle() can apply-then-throw.
     *
     * @param  array<string, mixed>  $args
     * @return ?array{0: GoogleAdsProviderException, 1: bool}
     */
    private function enter(string $method, ?GoogleAdsAccessContext $context, array $args = [], bool $mutation = false): ?array
    {
        $this->record($method, $context, $args);
        $this->budget?->reserve();

        $failure = isset($this->failures[$method]) ? array_shift($this->failures[$method]) : null;

        if ($failure === null) {
            return null;
        }

        if ($mutation && $failure[1]) {
            return $failure;
        }

        throw $failure[0];
    }

    /**
     * Applies a mutation, honouring a scripted "applied but failed" failure.
     *
     * @param  ?array{0: GoogleAdsProviderException, 1: bool}  $failure
     */
    private function settle(?array $failure, callable $apply): void
    {
        $apply();

        if ($failure !== null) {
            throw $failure[0];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, GoogleAdsAccessContext|string|null $context, array $args): void
    {
        $this->calls[] = [
            'method' => $method,
            'customer_id' => $context instanceof GoogleAdsAccessContext ? $context->customerId : null,
            'login_customer_id' => $context instanceof GoogleAdsAccessContext ? $context->loginCustomerId : null,
            'args' => $args,
        ];
    }

    /**
     * The provider rule: a customer reachable only through a manager needs
     * that manager as login-customer-id; an unknown customer is denied.
     */
    private function authorize(GoogleAdsAccessContext $context): void
    {
        $customerId = $context->customerId;

        if (in_array($customerId, $this->accessible, true)) {
            return;
        }

        foreach ($this->managerClientRows as $managerId => $clients) {
            foreach ($clients as $client) {
                if ($client->customerId === $customerId && $client->level > 0) {
                    if ($context->loginCustomerId !== (string) $managerId) {
                        throw GoogleAdsProviderException::accessDenied();
                    }

                    return;
                }
            }
        }

        throw GoogleAdsProviderException::accessDenied();
    }

    private function findCampaign(string $customerId, string $campaignId): ?int
    {
        foreach ($this->datasets[$customerId]['campaigns'] ?? [] as $i => $campaign) {
            if ($campaign->externalCampaignId === $campaignId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  'campaigns'|'adGroups'|'keywords'|'dailyCampaign'|'dailyKeyword'|'searchTerms'  $key
     */
    private function dataReport(string $method, string $key, GoogleAdsAccessContext $context, ?string $startDate = null, ?string $endDate = null): GoogleAdsReportResult
    {
        $args = $startDate === null ? [] : ['start_date' => $startDate, 'end_date' => $endDate];
        $this->enter($method, $context, $args);

        if ($startDate !== null) {
            // Same validation the real query builder applies, before any data.
            if (GoogleAdsJson::date($startDate) === null || GoogleAdsJson::date((string) $endDate) === null || $startDate > $endDate) {
                throw GoogleAdsProviderException::validation();
            }
        }

        $this->authorize($context);

        $rows = $this->datasets[$context->customerId][$key] ?? [];

        if ($startDate !== null) {
            $rows = array_filter($rows, static fn ($row): bool => $row->date >= $startDate && $row->date <= $endDate);
        }

        return $this->report($method, array_values($rows));
    }

    /**
     * Pages and caps rows exactly as the real transport does.
     *
     * @param  array<int, mixed>  $rows
     */
    private function report(string $method, array $rows): GoogleAdsReportResult
    {
        $maxPages = $this->config?->maxPagesPerReport() ?? 20;
        $maxRows = $this->config?->maxRowsPerReport() ?? 100000;
        $cap = min($this->rowCaps[$method] ?? PHP_INT_MAX, $maxRows);

        $pages = max(1, (int) ceil(count($rows) / $this->rowsPerPage));
        $truncated = false;

        if ($pages > $maxPages) {
            $rows = array_slice($rows, 0, $maxPages * $this->rowsPerPage);
            $pages = $maxPages;
            $truncated = true;
        }

        for ($page = 2; $page <= $pages; $page++) {
            $this->budget?->reserve();
        }

        if (count($rows) > $cap) {
            $rows = array_slice($rows, 0, $cap);
            $truncated = true;
        }

        return new GoogleAdsReportResult(array_values($rows), $truncated, $pages);
    }
}
