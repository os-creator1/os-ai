<?php

namespace Tests\Feature\GoogleAds\Sync\Support;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\DTO\GoogleBusinessProfile\GoogleAccessGrant;
use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;
use App\Library\GoogleAds\FakeGoogleAdsClient;
use Closure;

/**
 * A pass-through in front of FakeGoogleAdsClient that runs an optional
 * callback BEFORE every provider call, so a test can observe the process
 * state at the exact moment a request would leave (e.g. the DB transaction
 * level) or inject a competing action (a second sync) mid-run.
 */
final class HookedAdsClient implements GoogleAdsAuthClient, GoogleAdsReadClient
{
    /** @var (Closure(string): void)|null */
    public ?Closure $before = null;

    public function __construct(private readonly FakeGoogleAdsClient $inner)
    {
    }

    private function hook(string $method): void
    {
        if ($this->before !== null) {
            ($this->before)($method);
        }
    }

    public function authorizationUrl(string $signedState, bool $forceConsent): string
    {
        return $this->inner->authorizationUrl($signedState, $forceConsent);
    }

    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant
    {
        $this->hook(__FUNCTION__);

        return $this->inner->exchangeAuthorizationCode($code);
    }

    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant
    {
        $this->hook(__FUNCTION__);

        return $this->inner->exchangeRefreshToken($refreshToken);
    }

    public function listAccessibleCustomers(string $accessToken): array
    {
        $this->hook(__FUNCTION__);

        return $this->inner->listAccessibleCustomers($accessToken);
    }

    public function customerDetails(GoogleAdsAccessContext $context): GoogleAdsCustomerDetails
    {
        $this->hook(__FUNCTION__);

        return $this->inner->customerDetails($context);
    }

    public function managerClients(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->managerClients($context);
    }

    public function campaigns(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->campaigns($context);
    }

    public function adGroups(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->adGroups($context);
    }

    public function keywords(GoogleAdsAccessContext $context): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->keywords($context);
    }

    public function dailyCampaignMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->dailyCampaignMetrics($context, $startDate, $endDate);
    }

    public function dailyKeywordMetrics(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->dailyKeywordMetrics($context, $startDate, $endDate);
    }

    public function searchTerms(GoogleAdsAccessContext $context, string $startDate, string $endDate): GoogleAdsReportResult
    {
        $this->hook(__FUNCTION__);

        return $this->inner->searchTerms($context, $startDate, $endDate);
    }
}
