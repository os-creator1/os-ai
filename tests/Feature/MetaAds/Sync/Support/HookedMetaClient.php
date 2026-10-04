<?php

namespace Tests\Feature\MetaAds\Sync\Support;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\DTO\MetaAds\MetaReportPage;
use App\DTO\MetaAds\MetaTokenGrant;
use App\DTO\MetaAds\MetaUserProfile;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\Contracts\MetaReadClient;
use App\Library\MetaAds\FakeMetaClient;
use Closure;

/**
 * A pass-through in front of FakeMetaClient that runs an optional callback
 * BEFORE every provider call, so a test can observe the process state at the
 * exact moment a request would leave (e.g. the DB transaction level, the
 * access token it carries) or inject a competing action (a second sync, an
 * account re-selection) mid-run.
 *
 * The callback receives the method name and the 1-based ordinal of this call
 * to that method, so a test can act on e.g. the SECOND page of a report.
 */
final class HookedMetaClient implements MetaAuthClient, MetaReadClient
{
    /** @var (Closure(string, int, string): void)|null (method, ordinal, access token or '') */
    public ?Closure $before = null;

    /** @var array<string, int> */
    private array $ordinals = [];

    /** @var array<int, string> every access token a read call carried */
    public array $tokensSeen = [];

    public function __construct(private readonly FakeMetaClient $inner)
    {
    }

    private function hook(string $method, string $token = ''): void
    {
        $this->ordinals[$method] = ($this->ordinals[$method] ?? 0) + 1;

        if ($token !== '') {
            $this->tokensSeen[] = $token;
        }

        if ($this->before !== null) {
            ($this->before)($method, $this->ordinals[$method], $token);
        }
    }

    public function authorizationUrl(string $signedState): string
    {
        return $this->inner->authorizationUrl($signedState);
    }

    public function exchangeCode(string $code): MetaTokenGrant
    {
        $this->hook(__FUNCTION__);

        return $this->inner->exchangeCode($code);
    }

    public function profile(string $accessToken): MetaUserProfile
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->profile($accessToken);
    }

    public function grantedPermissions(string $accessToken): array
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->grantedPermissions($accessToken);
    }

    public function listAdAccounts(string $accessToken): array
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->listAdAccounts($accessToken);
    }

    public function accountDetails(string $accessToken, string $adAccountId): MetaAdsAccountCandidate
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->accountDetails($accessToken, $adAccountId);
    }

    public function campaigns(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->campaigns($accessToken, $adAccountId, $cursor);
    }

    public function adSets(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->adSets($accessToken, $adAccountId, $cursor);
    }

    public function ads(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->ads($accessToken, $adAccountId, $cursor);
    }

    public function insights(string $accessToken, string $adAccountId, string $level, string $since, string $until, ?string $cursor = null): MetaReportPage
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->insights($accessToken, $adAccountId, $level, $since, $until, $cursor);
    }

    public function frequency7d(string $accessToken, string $adAccountId, string $since, string $until, ?string $cursor = null): MetaReportPage
    {
        $this->hook(__FUNCTION__, $accessToken);

        return $this->inner->frequency7d($accessToken, $adAccountId, $since, $until, $cursor);
    }
}
