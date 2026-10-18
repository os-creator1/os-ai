<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\DTO\MetaAds\MetaAdsAdData;
use App\DTO\MetaAds\MetaAdsAdSetData;
use App\DTO\MetaAds\MetaAdsCampaignData;
use App\DTO\MetaAds\MetaApiUsage;
use App\DTO\MetaAds\MetaFrequencyRow;
use App\DTO\MetaAds\MetaInsightRow;
use App\DTO\MetaAds\MetaMutationResult;
use App\DTO\MetaAds\MetaReportPage;
use App\DTO\MetaAds\MetaTokenGrant;
use App\DTO\MetaAds\MetaUserProfile;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAdsCallCounter;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\Contracts\MetaMutationClient;
use App\Library\MetaAds\Contracts\MetaReadClient;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract §14 — the in-memory provider used by every
 * automated test and by browser acceptance (config `meta_ads.driver` = `fake`,
 * which MetaAdsConfig REFUSES in production). It implements all three provider
 * interfaces, so one instance is the whole fake Meta.
 *
 * SCRIPTABLE rather than canned (mirror of FakeGoogleAdsClient):
 *   - usePhotoBoothFixture() loads the deterministic MetaPhotoBoothFixture; ad
 *     accounts / per-account datasets / profile can also be set directly.
 *   - failNext($method, $exception, $times, $applyBeforeFailing) injects a
 *     provider failure into a named interface method (rate_limited 80004,
 *     provider_unavailable, timeout, invalid_token 190, an AMBIGUOUS mutate
 *     timeout ...). With $applyBeforeFailing a mutation takes effect on the
 *     fake provider and THEN fails: the "timed out but actually applied" case.
 *   - paginate($rowsPerPage) / limitRows($method, $max) shape cursor paging:
 *     a page carries at most min(limit, page size) rows and a `nextCursor`
 *     while more rows remain. The CALLER owns page / row caps, exactly as with
 *     the real client.
 *   - withUsage() makes every read page report API quota usage.
 *   - grantNoAdsRead() / grantScopes() script the permission check.
 *   - every call is recorded in $calls (method, ad account id, safe args;
 *     NEVER a token) for assertions, and every call reserves exactly one
 *     request on the MetaAdsCallCounter (when supplied), like the real client.
 *
 * Insight rows are filtered to config('meta_ads.result_types') on the way
 * out, exactly like the real client, so a type outside the allow-list can
 * never reach a caller.
 */
final class FakeMetaClient implements MetaAuthClient, MetaMutationClient, MetaReadClient
{
    /**
     * @var array<int, array{method: string, ad_account_id: ?string, args: array<string, mixed>}>
     */
    public array $calls = [];

    /** @var array<string, MetaAdsAccountCandidate> keyed by ad account id */
    private array $accounts = [];

    /**
     * @var array<string, array{campaigns: array<int, MetaAdsCampaignData>, adSets: array<int, MetaAdsAdSetData>, ads: array<int, MetaAdsAdData>, insights: array<string, array<int, MetaInsightRow>>, frequency: array<int, MetaFrequencyRow>}>
     */
    private array $datasets = [];

    /** @var array<string, array<int, array{0: MetaProviderException, 1: bool}>> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $rowCaps = [];

    private int $rowsPerPage;

    private MetaUserProfile $profile;

    /** @var array<int, string> */
    private array $permissions = ['public_profile', 'ads_read', 'ads_management'];

    private ?MetaApiUsage $usage = null;

    private int $tokenSequence = 0;

    public function __construct(
        private readonly ?MetaAdsCallCounter $counter = null,
        private readonly ?MetaAdsConfig $config = null,
    ) {
        $this->rowsPerPage = ($this->config ?? new MetaAdsConfig())->pageSize();
        $this->profile = new MetaUserProfile(MetaPhotoBoothFixture::META_USER_ID, MetaPhotoBoothFixture::META_USER_NAME);
    }

    // ------------------------------------------------------------------
    // Scripting
    // ------------------------------------------------------------------

    public function usePhotoBoothFixture(?MetaPhotoBoothFixture $fixture = null, ?CarbonImmutable $asOf = null): self
    {
        $fixture ??= new MetaPhotoBoothFixture();

        $this->withAdAccounts($fixture->adAccounts());
        $this->withProfile(new MetaUserProfile(MetaPhotoBoothFixture::META_USER_ID, MetaPhotoBoothFixture::META_USER_NAME));
        $this->withDataset(MetaPhotoBoothFixture::AD_ACCOUNT_ID, [
            'campaigns' => $fixture->campaigns($asOf),
            'adSets' => $fixture->adSets(),
            'ads' => $fixture->ads(),
            'insights' => [
                'campaign' => $fixture->insights('campaign', $asOf),
                'ad_set' => $fixture->insights('ad_set', $asOf),
                'ad' => $fixture->insights('ad', $asOf),
            ],
            'frequency' => $fixture->frequencyRows($asOf),
        ]);

        return $this;
    }

    /** @param  array<int, MetaAdsAccountCandidate>  $candidates */
    public function withAdAccounts(array $candidates): self
    {
        $this->accounts = [];

        foreach ($candidates as $candidate) {
            $this->accounts[$candidate->adAccountId] = $candidate;
        }

        return $this;
    }

    public function withProfile(MetaUserProfile $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * @param  array{campaigns?: array<int, MetaAdsCampaignData>, adSets?: array<int, MetaAdsAdSetData>, ads?: array<int, MetaAdsAdData>, insights?: array<string, array<int, MetaInsightRow>>, frequency?: array<int, MetaFrequencyRow>}  $data
     */
    public function withDataset(string $adAccountId, array $data): self
    {
        $insights = $data['insights'] ?? [];

        $this->datasets[$adAccountId] = [
            'campaigns' => $data['campaigns'] ?? [],
            'adSets' => $data['adSets'] ?? [],
            'ads' => $data['ads'] ?? [],
            'insights' => ['campaign' => $insights['campaign'] ?? [], 'ad_set' => $insights['ad_set'] ?? [], 'ad' => $insights['ad'] ?? []],
            'frequency' => $data['frequency'] ?? [],
        ];

        return $this;
    }

    /**
     * The next $times calls to $method (an interface method name) throw
     * $exception. For a mutation, $applyBeforeFailing makes the change take
     * effect on the fake provider before the throw.
     */
    public function failNext(string $method, MetaProviderException $exception, int $times = 1, bool $applyBeforeFailing = false): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$method][] = [$exception, $applyBeforeFailing];
        }

        return $this;
    }

    /** $method serves at most $maxRows rows per page (a `nextCursor` follows while more remain). */
    public function limitRows(string $method, int $maxRows): self
    {
        $this->rowCaps[$method] = max(1, $maxRows);

        return $this;
    }

    /** Every list serves at most $rowsPerPage rows per page. */
    public function paginate(int $rowsPerPage): self
    {
        $this->rowsPerPage = max(1, $rowsPerPage);

        return $this;
    }

    /** Every read page reports this quota usage (null clears it). */
    public function withUsage(?MetaApiUsage $usage): self
    {
        $this->usage = $usage;

        return $this;
    }

    /** `/me/permissions` no longer lists `ads_read` as granted. */
    public function grantNoAdsRead(): self
    {
        $this->permissions = ['public_profile'];

        return $this;
    }

    /** @param  array<int, string>  $permissions */
    public function grantScopes(array $permissions): self
    {
        $this->permissions = array_values($permissions);

        return $this;
    }

    /** @return array<int, array{method: string, ad_account_id: ?string, args: array<string, mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    public function callCount(?string $method = null): int
    {
        return $method === null ? count($this->calls) : count($this->callsTo($method));
    }

    public function campaignStatus(string $adAccountId, string $campaignId): ?string
    {
        foreach ($this->datasets[$adAccountId]['campaigns'] ?? [] as $campaign) {
            if ($campaign->externalCampaignId === $campaignId) {
                return $campaign->status;
            }
        }

        return null;
    }

    public function adSetStatus(string $adAccountId, string $adSetId): ?string
    {
        foreach ($this->datasets[$adAccountId]['adSets'] ?? [] as $adSet) {
            if ($adSet->externalAdSetId === $adSetId) {
                return $adSet->status;
            }
        }

        return null;
    }

    public function adStatus(string $adAccountId, string $adId): ?string
    {
        foreach ($this->datasets[$adAccountId]['ads'] ?? [] as $ad) {
            if ($ad->externalAdId === $adId) {
                return $ad->status;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // MetaAuthClient
    // ------------------------------------------------------------------

    public function authorizationUrl(string $signedState): string
    {
        $this->record('authorizationUrl', null, []);

        return 'https://www.facebook.test/' . $this->version() . '/dialog/oauth?' . http_build_query([
            'client_id' => 'fake-app',
            'state' => $signedState,
            'scope' => implode(',', MetaAdsConfig::SCOPES),
            'response_type' => 'code',
        ]);
    }

    public function exchangeCode(string $code): MetaTokenGrant
    {
        $this->enter('exchangeCode', null);
        // The real client makes two requests (code -> short -> long-lived).
        $this->counter?->reserve();

        return new MetaTokenGrant(
            'fake-long-lived-token-' . ++$this->tokenSequence,
            CarbonImmutable::now()->addDays(60),
        );
    }

    public function profile(string $accessToken): MetaUserProfile
    {
        $this->enter('profile', null);

        return $this->profile;
    }

    public function grantedPermissions(string $accessToken): array
    {
        $this->enter('grantedPermissions', null);

        return $this->permissions;
    }

    // ------------------------------------------------------------------
    // MetaReadClient
    // ------------------------------------------------------------------

    public function listAdAccounts(string $accessToken): array
    {
        $this->enter('listAdAccounts', null);

        $accounts = array_values($this->accounts);
        $pages = max(1, (int) ceil(count($accounts) / $this->rowsPerPage));

        for ($page = 2; $page <= $pages; $page++) {
            $this->counter?->reserve();
        }

        return $accounts;
    }

    public function accountDetails(string $accessToken, string $adAccountId): MetaAdsAccountCandidate
    {
        $this->enter('accountDetails', $adAccountId);
        $id = $this->accountId($adAccountId);

        return $this->accounts[$id] ?? throw MetaProviderException::accessDenied(200);
    }

    public function campaigns(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        return $this->listPage('campaigns', 'campaigns', $adAccountId, $cursor);
    }

    public function adSets(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        return $this->listPage('adSets', 'adSets', $adAccountId, $cursor);
    }

    public function ads(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        return $this->listPage('ads', 'ads', $adAccountId, $cursor);
    }

    public function insights(string $accessToken, string $adAccountId, string $level, string $since, string $until, ?string $cursor = null): MetaReportPage
    {
        $this->enter('insights', $adAccountId, ['level' => $level, 'since' => $since, 'until' => $until, 'cursor' => $cursor]);

        if (! in_array($level, MetaInsightRow::LEVELS, true)
            || MetaAdsJson::date($since) === null
            || MetaAdsJson::date($until) === null
            || $since > $until) {
            throw MetaProviderException::validation(100);
        }

        $id = $this->authorize($adAccountId);

        $rows = array_values(array_filter(
            $this->datasets[$id]['insights'][$level] ?? [],
            static fn (MetaInsightRow $row): bool => $row->date >= $since && $row->date <= $until,
        ));

        // Same allow-list filtering the real client applies.
        $rows = array_map(fn (MetaInsightRow $row): MetaInsightRow => new MetaInsightRow(
            $row->level,
            $row->externalId,
            $row->date,
            $row->spendMicros,
            $row->impressions,
            $row->clicks,
            $row->linkClicks,
            array_filter($row->results, fn (array $r, string $type): bool => ($this->config ?? new MetaAdsConfig())->isResultType($type), ARRAY_FILTER_USE_BOTH),
        ), $rows);

        return $this->page('insights', $rows, $cursor);
    }

    public function frequency7d(string $accessToken, string $adAccountId, string $since, string $until, ?string $cursor = null): MetaReportPage
    {
        $this->enter('frequency7d', $adAccountId, ['since' => $since, 'until' => $until, 'cursor' => $cursor]);

        if (MetaAdsJson::date($since) === null || MetaAdsJson::date($until) === null || $since > $until) {
            throw MetaProviderException::validation(100);
        }

        $id = $this->authorize($adAccountId);

        // The window is whatever was asked for, like a real non-daily insights call.
        $rows = array_map(
            static fn (MetaFrequencyRow $row): MetaFrequencyRow => new MetaFrequencyRow($row->externalAdSetId, $row->reach, $row->frequency, $since, $until),
            $this->datasets[$id]['frequency'] ?? [],
        );

        return $this->page('frequency7d', array_values($rows), $cursor);
    }

    // ------------------------------------------------------------------
    // MetaMutationClient
    // ------------------------------------------------------------------

    public function setStatus(string $accessToken, ?string $adAccountId, string $externalId, string $targetType, string $requestedState): MetaMutationResult
    {
        $failure = $this->enter('setStatus', $adAccountId, [
            'external_id' => $externalId,
            'target_type' => $targetType,
            'requested_state' => $requestedState,
        ], mutation: true);

        $id = MetaAdsJson::id($externalId) ?? throw MetaProviderException::validation(100);

        if (! in_array($targetType, ['campaign', 'ad_set', 'ad'], true) || ! in_array($requestedState, ['PAUSED', 'ACTIVE'], true)) {
            throw MetaProviderException::validation(100);
        }

        $account = $adAccountId === null ? $this->accountOwning($id, $targetType) : $this->authorize($adAccountId);

        if ($account === null) {
            throw MetaProviderException::notFound(803);
        }

        $index = $this->findIndex($account, $targetType, $id);

        if ($index === null) {
            throw MetaProviderException::notFound(803);
        }

        $this->guardTransition($account, $targetType, $index, $requestedState);

        $this->settle($failure, function () use ($account, $targetType, $index, $requestedState): void {
            $this->applyStatus($account, $targetType, $index, $requestedState);
        });

        return new MetaMutationResult($id, $requestedState);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Records the call, reserves one request, and takes any scripted failure.
     * A non-mutation failure throws here; a mutation failure flagged
     * `applyBeforeFailing` is returned so settle() can apply-then-throw.
     *
     * @param  array<string, mixed>  $args
     * @return ?array{0: MetaProviderException, 1: bool}
     */
    private function enter(string $method, ?string $adAccountId, array $args = [], bool $mutation = false): ?array
    {
        $this->record($method, $adAccountId, $args);
        $this->counter?->reserve();

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
     * @param  ?array{0: MetaProviderException, 1: bool}  $failure
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
    private function record(string $method, ?string $adAccountId, array $args): void
    {
        $this->calls[] = [
            'method' => $method,
            'ad_account_id' => $adAccountId !== null ? (MetaAdsJson::id($adAccountId) ?? $adAccountId) : null,
            'args' => $args,
        ];
    }

    private function version(): string
    {
        return ($this->config ?? new MetaAdsConfig())->apiVersion();
    }

    private function accountId(string $adAccountId): string
    {
        return MetaAdsJson::id($adAccountId) ?? throw MetaProviderException::validation(100);
    }

    /**
     * The provider rule: an ad account the token's user cannot reach (never
     * offered, or unknown) is access_denied.
     */
    private function authorize(string $adAccountId): string
    {
        $id = $this->accountId($adAccountId);

        if (! isset($this->accounts[$id]) && ! isset($this->datasets[$id])) {
            throw MetaProviderException::accessDenied(200);
        }

        return $id;
    }

    /**
     * @param  'campaigns'|'adSets'|'ads'  $key
     */
    private function listPage(string $method, string $key, string $adAccountId, ?string $cursor): MetaReportPage
    {
        $this->enter($method, $adAccountId, ['cursor' => $cursor]);
        $id = $this->authorize($adAccountId);

        return $this->page($method, $this->datasets[$id][$key] ?? [], $cursor);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function page(string $method, array $rows, ?string $cursor): MetaReportPage
    {
        $size = min($this->rowCaps[$method] ?? PHP_INT_MAX, $this->rowsPerPage);
        $offset = 0;

        if ($cursor !== null) {
            if (preg_match('/\Afake(\d{1,9})\z/', $cursor, $m) !== 1) {
                throw MetaProviderException::validation(100);
            }

            $offset = (int) $m[1];
        }

        $next = $offset + $size < count($rows) ? 'fake' . ($offset + $size) : null;

        return new MetaReportPage(array_slice(array_values($rows), $offset, $size), $next, $this->usage);
    }

    private function accountOwning(string $id, string $targetType): ?string
    {
        foreach (array_keys($this->datasets) as $account) {
            if ($this->findIndex($account, $targetType, $id) !== null) {
                return (string) $account;
            }
        }

        return null;
    }

    private function findIndex(string $account, string $targetType, string $id): ?int
    {
        [$key, $property] = match ($targetType) {
            'campaign' => ['campaigns', 'externalCampaignId'],
            'ad_set' => ['adSets', 'externalAdSetId'],
            default => ['ads', 'externalAdId'],
        };

        foreach ($this->datasets[$account][$key] ?? [] as $i => $entity) {
            if ($entity->{$property} === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * What Meta refuses: resuming a deleted / archived entity or an ad that
     * is DISAPPROVED / PENDING_REVIEW.
     */
    private function guardTransition(string $account, string $targetType, int $index, string $requestedState): void
    {
        $entity = $this->datasets[$account][match ($targetType) {
            'campaign' => 'campaigns',
            'ad_set' => 'adSets',
            default => 'ads',
        }][$index];

        if (in_array($entity->status, ['DELETED', 'ARCHIVED'], true)) {
            throw MetaProviderException::validation(100);
        }

        if ($requestedState === 'ACTIVE' && $targetType === 'ad' && in_array($entity->effectiveStatus, ['DISAPPROVED', 'PENDING_REVIEW'], true)) {
            throw MetaProviderException::validation(100);
        }
    }

    private function applyStatus(string $account, string $targetType, int $index, string $state): void
    {
        $effective = $state === 'PAUSED' ? 'PAUSED' : 'ACTIVE';

        if ($targetType === 'campaign') {
            $c = $this->datasets[$account]['campaigns'][$index];
            $this->datasets[$account]['campaigns'][$index] = new MetaAdsCampaignData(
                $c->externalCampaignId, $c->name, $state, $effective, $c->objective, $c->dailyBudgetMinor,
                $c->lifetimeBudgetMinor, $c->budgetRemainingMinor, $c->startTime, $c->stopTime,
            );

            // Pausing a campaign pauses its ACTIVE ad sets (effective CAMPAIGN_PAUSED); resuming reverses it.
            foreach ($this->datasets[$account]['adSets'] as $i => $s) {
                if ($s->externalCampaignId !== $c->externalCampaignId || $s->status !== 'ACTIVE') {
                    continue;
                }

                if ($state === 'PAUSED' && $s->effectiveStatus === 'ACTIVE') {
                    $this->datasets[$account]['adSets'][$i] = $this->withAdSetEffective($s, 'CAMPAIGN_PAUSED');
                } elseif ($state === 'ACTIVE' && $s->effectiveStatus === 'CAMPAIGN_PAUSED') {
                    $this->datasets[$account]['adSets'][$i] = $this->withAdSetEffective($s, 'ACTIVE');
                }
            }

            return;
        }

        if ($targetType === 'ad_set') {
            $s = $this->datasets[$account]['adSets'][$index];
            $this->datasets[$account]['adSets'][$index] = new MetaAdsAdSetData(
                $s->externalAdSetId, $s->externalCampaignId, $s->name, $state, $effective, $s->dailyBudgetMinor,
                $s->lifetimeBudgetMinor, $s->optimizationGoal, $s->bidStrategy, $s->targetingSummary,
            );

            return;
        }

        $a = $this->datasets[$account]['ads'][$index];
        $this->datasets[$account]['ads'][$index] = new MetaAdsAdData(
            $a->externalAdId, $a->externalCampaignId, $a->externalAdSetId, $a->name, $state,
            // A resumed ad keeps a problem status Meta already reported.
            $state === 'ACTIVE' && in_array($a->effectiveStatus, ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_REVIEW'], true) ? $a->effectiveStatus : $effective,
            $a->creativeTitle, $a->creativeBody, $a->creativeThumbnailUrl, $a->creativeObjectType,
        );
    }

    private function withAdSetEffective(MetaAdsAdSetData $s, string $effective): MetaAdsAdSetData
    {
        return new MetaAdsAdSetData(
            $s->externalAdSetId, $s->externalCampaignId, $s->name, $s->status, $effective, $s->dailyBudgetMinor,
            $s->lifetimeBudgetMinor, $s->optimizationGoal, $s->bidStrategy, $s->targetingSummary,
        );
    }
}
