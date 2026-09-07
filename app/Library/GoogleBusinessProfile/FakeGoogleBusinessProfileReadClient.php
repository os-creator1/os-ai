<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleAccessGrant;
use App\DTO\GoogleBusinessProfile\GoogleAccountSummary;
use App\DTO\GoogleBusinessProfile\GoogleLocationCandidate;
use App\DTO\GoogleBusinessProfile\GoogleLocationProfile;
use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\DTO\GoogleBusinessProfile\GoogleVoiceOfMerchantState;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;

/**
 * GBP Slice A contract §14.3 — the deterministic in-memory double, in the
 * same shape as App\Library\Usage\FakePaymentProviderGateway and
 * App\Library\AgencyProspecting\FakeAgencyProspectingAiClient.
 *
 * Every automated test binds this (contract §32, T-FAKE-1/T-FAKE-2), so no
 * real HTTP request to any googleapis.com host ever occurs in the suite
 * and no real Google credential is required.
 *
 * Contract §14.3 requires it to be able to produce, on demand: multiple
 * accounts across several pages; multiple locations across several pages;
 * a location whose RAW response contains a storefrontAddress even though
 * the read mask omitted it (to prove §23.3); an invalid_grant refresh
 * failure; an HTTP 429; and a timeout. All six are supported below.
 *
 * It records every call so tests can assert BOTH what was sent (read
 * masks, T-MASK-1/T-PRIV-1) and how many calls happened
 * (T-SYNC-4 coalescing, T-OAUTH-* "zero token exchange").
 *
 * Like the real client, it declares NO mutation method — T-PROV-1
 * reflects over both implementations.
 */
final class FakeGoogleBusinessProfileReadClient implements GoogleBusinessProfileReadClient
{
    public function __construct(private readonly GoogleBusinessProfileCallBudget $budget)
    {
    }

    /**
     * Correction pass item 6 — how many PAGES a list call should simulate.
     * Each page reserves one outbound request against the Business budget,
     * exactly as the real client does in its paging loop, so pagination
     * accounting is testable without real HTTP.
     */
    public int $pagesPerListCall = 1;

    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, GoogleAccountSummary> */
    public array $accounts = [];

    /** @var array<string, array<int, GoogleLocationCandidate>> keyed by account resource name */
    public array $locationsByAccount = [];

    /** @var array<string, array<string, mixed>> raw location payloads keyed by location resource name */
    public array $rawLocations = [];

    public ?GoogleVoiceOfMerchantState $voiceOfMerchantState = null;

    public ?string $refreshTokenToIssue = 'fake-refresh-token';

    public string $accessTokenToIssue = 'fake-access-token';

    /**
     * Set to a GoogleBusinessProfileProviderException factory result to
     * make the NEXT provider call fail with that classification. Cleared
     * after it fires, so a test can prove a single failure followed by
     * recovery.
     */
    public ?GoogleBusinessProfileProviderException $failNextWith = null;

    /** Fails every code exchange, for the OAuth failure path. */
    public ?GoogleBusinessProfileProviderException $failCodeExchangeWith = null;

    /** Fails every refresh exchange — e.g. invalid_grant (T-SEC-3). */
    public ?GoogleBusinessProfileProviderException $failRefreshWith = null;

    public function authorizationUrl(string $signedState, bool $forceConsent): string
    {
        $this->calls[] = ['method' => 'authorizationUrl', 'force_consent' => $forceConsent];

        return 'https://accounts.google.test/authorize?state=' . urlencode($signedState)
            . ($forceConsent ? '&prompt=consent' : '');
    }

    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant
    {
        // Correction pass item 6 — reserve BEFORE the call is recorded,
        // exactly as the real client reserves before it opens a socket. An
        // exhausted budget must produce ZERO provider calls, not a
        // recorded-then-refused one. An OAuth token exchange is a real
        // outbound request and is accounted like any other.
        $this->budget->reserve();

        $this->calls[] = ['method' => 'exchangeAuthorizationCode'];

        if ($this->failCodeExchangeWith !== null) {
            throw $this->failCodeExchangeWith;
        }

        $this->consumeOneOffFailure();

        return new GoogleTokenGrant(
            refreshToken: $this->refreshTokenToIssue,
            accessToken: $this->accessTokenToIssue,
            expiresInSeconds: 3599,
            grantedScopes: HttpGoogleBusinessProfileReadClient::SCOPE,
        );
    }

    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant
    {
        $this->budget->reserve();

        $this->calls[] = ['method' => 'exchangeRefreshToken'];

        if ($this->failRefreshWith !== null) {
            throw $this->failRefreshWith;
        }

        $this->consumeOneOffFailure();

        return new GoogleAccessGrant($this->accessTokenToIssue, 3599);
    }

    public function listAccounts(string $accessToken): array
    {
        $this->reservePages();

        $this->calls[] = ['method' => 'listAccounts'];
        $this->consumeOneOffFailure();

        return $this->accounts;
    }

    public function listLocations(string $accessToken, string $accountResourceName, array $readMask, bool $addressPermitted): array
    {
        $this->reservePages();

        $this->calls[] = [
            'method' => 'listLocations',
            'account' => $accountResourceName,
            'read_mask' => $readMask,
            'address_permitted' => $addressPermitted,
        ];

        $this->consumeOneOffFailure();

        $candidates = $this->locationsByAccount[$accountResourceName] ?? [];

        // Re-derive from the raw payload when one is registered, so a test
        // can prove §23.3: an address present in the RAW response is
        // discarded before it reaches a DTO.
        return array_values(array_filter(array_map(function (GoogleLocationCandidate $candidate) use ($accountResourceName, $addressPermitted) {
            $raw = $this->rawLocations[$candidate->resourceName] ?? null;

            if ($raw === null) {
                return $addressPermitted ? $candidate : new GoogleLocationCandidate(
                    resourceName: $candidate->resourceName,
                    accountResourceName: $candidate->accountResourceName,
                    title: $candidate->title,
                    storeCode: $candidate->storeCode,
                    localityHint: null,
                    regionCode: null,
                );
            }

            return GoogleLocationCandidate::fromProviderArray($raw, $accountResourceName, $addressPermitted);
        }, $candidates)));
    }

    public function getLocation(string $accessToken, string $locationResourceName, array $readMask, bool $addressPermitted): GoogleLocationProfile
    {
        $this->budget->reserve();

        $this->calls[] = [
            'method' => 'getLocation',
            'location' => $locationResourceName,
            'read_mask' => $readMask,
            'address_permitted' => $addressPermitted,
        ];

        $this->consumeOneOffFailure();

        $raw = $this->rawLocations[$locationResourceName] ?? null;

        if ($raw === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        $profile = GoogleLocationProfile::fromProviderArray($raw, $addressPermitted);

        if ($profile === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return $profile;
    }

    public function getVoiceOfMerchantState(string $accessToken, string $locationResourceName): GoogleVoiceOfMerchantState
    {
        $this->budget->reserve();

        $this->calls[] = ['method' => 'getVoiceOfMerchantState', 'location' => $locationResourceName];

        $this->consumeOneOffFailure();

        return $this->voiceOfMerchantState ?? new GoogleVoiceOfMerchantState(
            hasVoiceOfMerchant: true,
            hasBusinessAuthority: true,
            hasPendingVerification: false,
            isWaitingForVoiceOfMerchant: false,
            hasOwnershipConflict: false,
            complianceReason: null,
        );
    }

    // -----------------------------------------------------------------
    // Test-facing builders
    // -----------------------------------------------------------------

    public function withAccount(string $resourceName, string $accountName = 'Fake Account', string $role = 'PRIMARY_OWNER'): self
    {
        $this->accounts[] = new GoogleAccountSummary(
            resourceName: $resourceName,
            accountName: $accountName,
            type: 'LOCATION_GROUP',
            role: $role,
            verificationState: 'VERIFIED',
        );

        return $this;
    }

    /**
     * Registers a location as BOTH a candidate and a raw payload, so the
     * chooser and the mirror read exercise the same fixture.
     *
     * @param  array<string, mixed>  $rawOverrides
     */
    public function withLocation(string $accountResourceName, string $locationResourceName, array $rawOverrides = []): self
    {
        $raw = array_replace_recursive([
            'name' => $locationResourceName,
            'title' => 'Fake Location',
            'storeCode' => null,
            'phoneNumbers' => ['primaryPhone' => '+15550101234'],
            'websiteUri' => 'https://example.test',
            'categories' => [
                'primaryCategory' => ['name' => 'categories/gcid:florist', 'displayName' => 'Florist'],
            ],
            'latlng' => ['latitude' => 40.7128, 'longitude' => -74.006],
            'openInfo' => ['status' => 'OPEN'],
            'metadata' => [
                'hasVoiceOfMerchant' => true,
                'hasPendingEdits' => false,
                'mapsUri' => 'https://maps.google.test/place',
                'newReviewUri' => 'https://search.google.test/review',
            ],
        ], $rawOverrides);

        $this->rawLocations[$locationResourceName] = $raw;

        $candidate = GoogleLocationCandidate::fromProviderArray($raw, $accountResourceName, true);

        if ($candidate !== null) {
            $this->locationsByAccount[$accountResourceName][] = $candidate;
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    public function callCount(string $method): int
    {
        return count($this->callsTo($method));
    }

    public function clearCalls(): void
    {
        $this->calls = [];
        $this->failNextWith = null;
        $this->failCodeExchangeWith = null;
        $this->failRefreshWith = null;
    }

    /**
     * One reservation per simulated page, matching the real client's
     * per-page getJson() reservation (correction pass item 6).
     */
    private function reservePages(): void
    {
        $pages = max(1, $this->pagesPerListCall);

        for ($page = 0; $page < $pages; $page++) {
            $this->budget->reserve();
        }
    }

    private function consumeOneOffFailure(): void
    {
        if ($this->failNextWith === null) {
            return;
        }

        $failure = $this->failNextWith;
        $this->failNextWith = null;

        throw $failure;
    }
}
