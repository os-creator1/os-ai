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
use App\Library\GoogleBusinessProfile\GoogleProviderValueNormalizer as N;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GBP Slice A contract §7 / §14 — the real provider client.
 *
 * READ-ONLY BY CONSTRUCTION:
 *   - Every Business Profile call below is `Http::get()`. There is no
 *     other verb against a googleapis.com host anywhere in this class.
 *   - The only POSTs are the two OAuth token endpoints, which are not
 *     Business Profile resources.
 *   - There is no generic request()/call()/send() method, so product code
 *     cannot reach an arbitrary endpoint through this seam.
 *   - Every URL is a COMPILE-TIME CONSTANT host plus a resource name
 *     validated against ^accounts/[A-Za-z0-9_-]+$ or
 *     ^locations/[A-Za-z0-9_-]+$ (contract §14.4). Nothing user-supplied,
 *     database-supplied or provider-supplied is ever fetched.
 *
 * APIs used, all current v1 (contract §7). No v4/v4.9 endpoint appears
 * anywhere in this class:
 *   - My Business Account Management v1  — GET /v1/accounts
 *   - My Business Business Information v1 — GET /v1/{parent=accounts/*}/locations
 *                                          GET /v1/{name=locations/*}
 *   - My Business Verifications v1        — GET /v1/{name=locations/*}/VoiceOfMerchantState
 *
 * Provider failures are normalized to a closed classification
 * (GoogleBusinessProfileProviderException). No response body, provider
 * message or upstream exception ever escapes this class.
 */
final class HttpGoogleBusinessProfileReadClient implements GoogleBusinessProfileReadClient
{
    private const OAUTH_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const OAUTH_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * Contract §9.2 — the single scope Google offers for Business Profile.
     * There is no read-only variant; see the class docblock.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/business.manage';

    private const ACCOUNT_MANAGEMENT_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    private const BUSINESS_INFORMATION_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const VERIFICATIONS_BASE = 'https://mybusinessverifications.googleapis.com/v1';

    /** Contract §7.1 — Google's documented maximum page size for accounts.list. */
    private const ACCOUNTS_PAGE_SIZE = 20;

    /** Contract §7.1 — Google's documented maximum page size for accounts.locations.list. */
    private const LOCATIONS_PAGE_SIZE = 100;

    /**
     * A hard ceiling on paging so a pathological or hostile provider
     * response cannot spin a request forever. 50 pages of 100 is 5,000
     * locations, far beyond any realistic tenant.
     */
    private const MAX_PAGES = 50;

    public function authorizationUrl(string $signedState, bool $forceConsent): string
    {
        $query = [
            'client_id' => (string) config('services.google_business_profile.client_id'),
            'redirect_uri' => (string) config('services.google_business_profile.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // Contract §9.3 — required for a refresh token.
            'access_type' => 'offline',
            // Contract §9.2 — incremental authorization is useless here
            // (one scope), so it is explicitly off rather than implicit.
            'include_granted_scopes' => 'false',
            'state' => $signedState,
        ];

        if ($forceConsent) {
            // Contract §9.3 — only when there is no stored refresh token,
            // or the connection is revoked. A reconnect of a healthy
            // connection does not force consent.
            $query['prompt'] = 'consent';
        }

        return self::OAUTH_AUTHORIZE_ENDPOINT . '?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant
    {
        $payload = $this->postToken([
            'code' => $code,
            'client_id' => (string) config('services.google_business_profile.client_id'),
            'client_secret' => (string) config('services.google_business_profile.client_secret'),
            'redirect_uri' => (string) config('services.google_business_profile.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = N::string($payload['access_token'] ?? null, 4096);

        if ($accessToken === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return new GoogleTokenGrant(
            refreshToken: N::string($payload['refresh_token'] ?? null, 4096),
            accessToken: $accessToken,
            expiresInSeconds: N::integer($payload['expires_in'] ?? null) ?? 0,
            grantedScopes: N::string($payload['scope'] ?? null, 512) ?? self::SCOPE,
        );
    }

    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant
    {
        $payload = $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id' => (string) config('services.google_business_profile.client_id'),
            'client_secret' => (string) config('services.google_business_profile.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = N::string($payload['access_token'] ?? null, 4096);

        if ($accessToken === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return new GoogleAccessGrant(
            accessToken: $accessToken,
            expiresInSeconds: N::integer($payload['expires_in'] ?? null) ?? 0,
        );
    }

    public function listAccounts(string $accessToken): array
    {
        $accounts = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['pageSize' => self::ACCOUNTS_PAGE_SIZE];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->getJson(self::ACCOUNT_MANAGEMENT_BASE . '/accounts', $query, $accessToken);

            foreach (N::listOf($payload['accounts'] ?? null, self::ACCOUNTS_PAGE_SIZE) as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $account = GoogleAccountSummary::fromProviderArray($raw);

                if ($account !== null) {
                    $accounts[] = $account;
                }
            }

            $pageToken = N::string($payload['nextPageToken'] ?? null, 4096);

            if ($pageToken === null) {
                break;
            }
        }

        return $accounts;
    }

    public function listLocations(string $accessToken, string $accountResourceName, array $readMask, bool $addressPermitted): array
    {
        $account = N::accountResourceName($accountResourceName);

        if ($account === null || $readMask === []) {
            // A missing or malformed account name, or an empty readMask
            // (which Google requires), is a programming error — never a
            // request we widen or default our way out of.
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        $locations = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = [
                'pageSize' => self::LOCATIONS_PAGE_SIZE,
                'readMask' => implode(',', $readMask),
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->getJson(
                self::BUSINESS_INFORMATION_BASE . '/' . $account . '/locations',
                $query,
                $accessToken,
            );

            foreach (N::listOf($payload['locations'] ?? null, self::LOCATIONS_PAGE_SIZE) as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $candidate = GoogleLocationCandidate::fromProviderArray($raw, $account, $addressPermitted);

                if ($candidate !== null) {
                    $locations[] = $candidate;
                }
            }

            $pageToken = N::string($payload['nextPageToken'] ?? null, 4096);

            if ($pageToken === null) {
                break;
            }
        }

        return $locations;
    }

    public function getLocation(string $accessToken, string $locationResourceName, array $readMask, bool $addressPermitted): GoogleLocationProfile
    {
        $location = N::locationResourceName($locationResourceName);

        if ($location === null || $readMask === []) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        $payload = $this->getJson(
            self::BUSINESS_INFORMATION_BASE . '/' . $location,
            ['readMask' => implode(',', $readMask)],
            $accessToken,
        );

        $profile = GoogleLocationProfile::fromProviderArray($payload, $addressPermitted);

        if ($profile === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return $profile;
    }

    public function getVoiceOfMerchantState(string $accessToken, string $locationResourceName): GoogleVoiceOfMerchantState
    {
        $location = N::locationResourceName($locationResourceName);

        if ($location === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        $payload = $this->getJson(
            self::VERIFICATIONS_BASE . '/' . $location . '/VoiceOfMerchantState',
            [],
            $accessToken,
        );

        return new GoogleVoiceOfMerchantState(
            hasVoiceOfMerchant: N::boolean($payload['hasVoiceOfMerchant'] ?? null),
            hasBusinessAuthority: N::boolean($payload['hasBusinessAuthority'] ?? null),
            hasPendingVerification: N::boolean(N::dig($payload, ['verify', 'hasPendingVerification'])) === true,
            // Google defines waitForVoiceOfMerchant / resolveOwnershipConflict
            // as objects whose PRESENCE is the signal (the latter is
            // documented as an empty object).
            isWaitingForVoiceOfMerchant: array_key_exists('waitForVoiceOfMerchant', $payload),
            hasOwnershipConflict: array_key_exists('resolveOwnershipConflict', $payload),
            complianceReason: N::enumValue(
                N::dig($payload, ['complyWithGuidelines', 'recommendationReason']),
                ['RECOMMENDATION_REASON_UNSPECIFIED', 'BUSINESS_LOCATION_SUSPENDED', 'BUSINESS_LOCATION_DISABLED'],
            ),
        );
    }

    /**
     * The ONLY Business Profile transport in this class, and it is GET.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJson(string $url, array $query, string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->accept('application/json')
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                // Contract §14.4 — never follow a redirect to another
                // host. Google does not redirect these endpoints; a
                // redirect is a signal, not something to chase.
                ->withOptions(['allow_redirects' => false])
                ->get($url, $query);
        } catch (ConnectionException) {
            // Ambiguous: the request may or may not have reached Google.
            throw GoogleBusinessProfileProviderException::timeout();
        } catch (Throwable) {
            throw GoogleBusinessProfileProviderException::providerUnavailable();
        }

        return $this->decode($this->guard($response));
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function postToken(array $form): array
    {
        try {
            $response = Http::asForm()
                ->accept('application/json')
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                ->withOptions(['allow_redirects' => false])
                ->post(self::OAUTH_TOKEN_ENDPOINT, $form);
        } catch (ConnectionException) {
            throw GoogleBusinessProfileProviderException::timeout();
        } catch (Throwable) {
            throw GoogleBusinessProfileProviderException::providerUnavailable();
        }

        // Google signals a dead/revoked authorization with HTTP 400 and an
        // `error` of invalid_grant on the token endpoint specifically.
        if ($response->status() === 400 && $this->errorCode($response) === 'invalid_grant') {
            throw GoogleBusinessProfileProviderException::invalidGrant();
        }

        return $this->decode($this->guard($response));
    }

    private function guard(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        // Contract §24.5 — 429 / RESOURCE_EXHAUSTED is deferral, not
        // failure. Google publishes no Retry-After header for these APIs,
        // so backoff is time-based only and lives in the caller.
        if ($response->status() === 429) {
            throw GoogleBusinessProfileProviderException::rateLimited();
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw $this->errorCode($response) === 'invalid_grant'
                ? GoogleBusinessProfileProviderException::invalidGrant()
                : GoogleBusinessProfileProviderException::accessDenied();
        }

        if ($response->serverError()) {
            throw GoogleBusinessProfileProviderException::providerUnavailable();
        }

        throw GoogleBusinessProfileProviderException::unexpectedResponse();
    }

    /**
     * Reads ONLY the machine-readable error code, never the human-readable
     * description — Google's descriptions can echo request content
     * (contract §27: no raw provider error text may be stored or logged).
     */
    private function errorCode(Response $response): ?string
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($body)) {
            return null;
        }

        return N::string($body['error'] ?? null, 64)
            ?? N::string(N::dig($body, ['error', 'status']), 64);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        if (! is_array($decoded)) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return $decoded;
    }

    private function connectTimeout(): int
    {
        $configured = config('google_business_profile.http.connect_timeout_seconds');

        return is_int($configured) && $configured > 0 ? $configured : 5;
    }

    private function requestTimeout(): int
    {
        $configured = config('google_business_profile.http.request_timeout_seconds');

        return is_int($configured) && $configured > 0 ? $configured : 20;
    }
}
