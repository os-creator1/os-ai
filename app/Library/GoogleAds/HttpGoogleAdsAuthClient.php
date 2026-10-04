<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleBusinessProfile\GoogleAccessGrant;
use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Ads Module V1 contract §2/§3 — the real OAuth client (mirror of the
 * OAuth half of HttpGoogleBusinessProfileReadClient, with the DEDICATED
 * `services.google_ads` credentials and the adwords scope).
 *
 * The only requests are the two token-endpoint POSTs; both are reserved
 * against the per-Business call budget like any other outbound request.
 * Nothing is persisted here. A dead / revoked authorization is Google's
 * HTTP 400 `invalid_grant` on the token endpoint.
 */
final class HttpGoogleAdsAuthClient implements GoogleAdsAuthClient
{
    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly GoogleAdsCallBudget $budget,
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsOAuthConfig $oauth,
    ) {
    }

    public function authorizationUrl(string $signedState, bool $forceConsent): string
    {
        $query = [
            'client_id' => (string) $this->oauth->clientId(),
            'redirect_uri' => (string) $this->oauth->redirect(),
            'response_type' => 'code',
            'scope' => GoogleConnectionProduct::GoogleAds->scope(),
            // Required for a refresh token.
            'access_type' => 'offline',
            // One scope: incremental authorization is explicitly off.
            'include_granted_scopes' => 'false',
            'state' => $signedState,
        ];

        if ($forceConsent) {
            // Google returns a refresh token only on a fresh consent.
            $query['prompt'] = 'consent';
        }

        return self::AUTHORIZE_ENDPOINT . '?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant
    {
        $payload = $this->postToken([
            'code' => $code,
            'client_id' => (string) $this->oauth->clientId(),
            'client_secret' => (string) $this->oauth->clientSecret(),
            'redirect_uri' => (string) $this->oauth->redirect(),
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = GoogleAdsJson::string($payload['access_token'] ?? null, 4096);

        if ($accessToken === null) {
            throw GoogleAdsProviderException::unexpectedResponse();
        }

        return new GoogleTokenGrant(
            refreshToken: GoogleAdsJson::string($payload['refresh_token'] ?? null, 4096),
            accessToken: $accessToken,
            expiresInSeconds: is_int($payload['expires_in'] ?? null) ? $payload['expires_in'] : 0,
            grantedScopes: GoogleAdsJson::string($payload['scope'] ?? null, 512) ?? GoogleConnectionProduct::GoogleAds->scope(),
        );
    }

    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant
    {
        $payload = $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id' => (string) $this->oauth->clientId(),
            'client_secret' => (string) $this->oauth->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = GoogleAdsJson::string($payload['access_token'] ?? null, 4096);

        if ($accessToken === null) {
            throw GoogleAdsProviderException::unexpectedResponse();
        }

        return new GoogleAccessGrant(
            accessToken: $accessToken,
            expiresInSeconds: is_int($payload['expires_in'] ?? null) ? $payload['expires_in'] : 0,
        );
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function postToken(array $form): array
    {
        $this->budget->reserve();

        try {
            $response = Http::asForm()
                ->accept('application/json')
                ->connectTimeout($this->config->connectTimeoutSeconds())
                ->timeout($this->config->requestTimeoutSeconds())
                ->withOptions(['allow_redirects' => false])
                ->post(self::TOKEN_ENDPOINT, $form);
        } catch (ConnectionException) {
            throw GoogleAdsProviderException::timeout();
        } catch (Throwable) {
            throw GoogleAdsProviderException::providerUnavailable();
        }

        if ($response->status() === 400 && $this->errorCode($response) === 'invalid_grant') {
            throw GoogleAdsProviderException::invalidGrant();
        }

        if (! $response->successful()) {
            throw match (true) {
                $response->status() === 429 => GoogleAdsProviderException::rateLimited(),
                in_array($response->status(), [400, 401, 403], true) => GoogleAdsProviderException::accessDenied(),
                $response->serverError() => GoogleAdsProviderException::providerUnavailable(),
                default => GoogleAdsProviderException::unexpectedResponse(),
            };
        }

        return $this->decode($response);
    }

    /** Reads ONLY the machine-readable OAuth error code, never the description. */
    private function errorCode(Response $response): ?string
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($body) ? GoogleAdsJson::string($body['error'] ?? null, 64) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            throw GoogleAdsProviderException::unexpectedResponse();
        }

        if (! is_array($decoded)) {
            throw GoogleAdsProviderException::unexpectedResponse();
        }

        return $decoded;
    }
}
